<?php

declare(strict_types=1);

namespace App\Domain\DeviceControl\Services;

use App\Domain\DeviceControl\Enums\CommandStatus;
use App\Domain\DeviceControl\Models\DeviceCommandLog;
use App\Domain\DeviceControl\Models\DeviceDesiredTopicState;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Publishing\Mqtt\MqttCommandPublisher;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Events\CommandDispatched;
use App\Events\CommandSent;
use Illuminate\Log\LogManager;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

final readonly class DeviceCommandDispatcher
{
    private const int MAX_MQTT_PUBLISH_ATTEMPTS = 2;

    private const int MQTT_PUBLISH_RETRY_DELAY_MICROSECONDS = 250_000;

    public function __construct(
        private MqttCommandPublisher $mqttPublisher,
        private LogManager $logManager,
    ) {}

    /**
     * Dispatch a command from the platform to a device via MQTT.
     *
     * Creates a DeviceCommandLog, publishes to the MQTT broker (NATS MQTT bridge),
     * and broadcasts Reverb events at each lifecycle step.
     *
     * @param  array<string, mixed>  $payload
     */
    public function dispatch(
        Device $device,
        SchemaVersionTopic $topic,
        array $payload,
        ?int $userId = null,
        ?string $host = null,
        ?int $port = null,
    ): DeviceCommandLog {
        $device->loadMissing('deviceType');
        $correlationId = (string) Str::uuid();
        $payloadForPublishing = $this->injectCorrelationMeta($payload, $correlationId);
        $resolvedHost = $this->resolveMqttHost($host);
        $resolvedPort = $this->resolveMqttPort($port);
        $mqttTopic = $this->resolveTopicWithExternalId($device, $topic);

        $this->log()->debug('Dispatching command', [
            'device_id' => $device->id,
            'device_external_id' => $device->external_id,
            'device_uuid' => $device->uuid,
            'topic_id' => $topic->id,
            'topic_suffix' => $topic->suffix,
            'mqtt_topic' => $mqttTopic,
            'mqtt_host' => $resolvedHost,
            'mqtt_port' => $resolvedPort,
            'correlation_id' => $correlationId,
            'payload' => $payload,
            'user_id' => $userId,
        ]);

        $commandLog = DeviceCommandLog::create([
            'device_id' => $device->id,
            'schema_version_topic_id' => $topic->id,
            'user_id' => $userId,
            'command_payload' => $payload,
            'correlation_id' => $correlationId,
            'status' => CommandStatus::Pending,
        ]);

        DeviceDesiredTopicState::updateOrCreate(
            [
                'device_id' => $device->id,
                'schema_version_topic_id' => $topic->id,
            ],
            [
                'desired_payload' => $payload,
                'correlation_id' => $correlationId,
                'reconciled_at' => null,
            ],
        );

        $commandLog->load('device', 'topic');

        $this->log()->debug('Broadcasting CommandDispatched', [
            'command_log_id' => $commandLog->id,
            'correlation_id' => $correlationId,
        ]);

        try {
            event(new CommandDispatched($commandLog));
        } catch (\Throwable $broadcastException) {
            $this->log()->warning('CommandDispatched broadcast failed (non-fatal)', [
                'command_log_id' => $commandLog->id,
                'error' => $broadcastException->getMessage(),
            ]);
        }

        $natsSubject = str_replace('/', '.', $mqttTopic);

        $encodedPayload = json_encode($payloadForPublishing);
        $encodedPayload = is_string($encodedPayload) ? $encodedPayload : '{}';

        try {
            $this->log()->debug('Publishing MQTT command', [
                'command_log_id' => $commandLog->id,
                'mqtt_topic' => $mqttTopic,
                'mqtt_host' => $resolvedHost,
                'mqtt_port' => $resolvedPort,
                'payload_size' => strlen($encodedPayload),
            ]);

            $this->publishToMqttWithRetry($mqttTopic, $encodedPayload, $resolvedHost, $resolvedPort, $commandLog->id);

            $commandLog->update([
                'status' => CommandStatus::Sent,
                'sent_at' => now(),
            ]);

            $commandLog->refresh();

            $this->log()->debug('Command sent successfully', [
                'command_log_id' => $commandLog->id,
                'correlation_id' => $correlationId,
                'nats_subject' => $natsSubject,
                'sent_at' => (string) $commandLog->sent_at,
            ]);

            $this->log()->debug('Broadcasting CommandSent', [
                'command_log_id' => $commandLog->id,
                'nats_subject' => $natsSubject,
            ]);

            try {
                event(new CommandSent($commandLog, $natsSubject));
            } catch (\Throwable $broadcastException) {
                $this->log()->warning('CommandSent broadcast failed (non-fatal)', [
                    'command_log_id' => $commandLog->id,
                    'error' => $broadcastException->getMessage(),
                ]);
            }
        } catch (\Throwable $exception) {
            $this->log()->error('Command publish failed', [
                'command_log_id' => $commandLog->id,
                'correlation_id' => $correlationId,
                'mqtt_topic' => $mqttTopic,
                'mqtt_host' => $resolvedHost,
                'mqtt_port' => $resolvedPort,
                'error' => $exception->getMessage(),
            ]);

            $commandLog->update([
                'status' => CommandStatus::Failed,
                'error_message' => $exception->getMessage(),
            ]);
        }

        return $commandLog;
    }

    private function resolveMqttHost(?string $host): string
    {
        if (is_string($host) && trim($host) !== '') {
            return trim($host);
        }

        $configuredHost = config('iot.mqtt.host', '127.0.0.1');

        return is_string($configuredHost) && trim($configuredHost) !== ''
            ? trim($configuredHost)
            : '127.0.0.1';
    }

    private function resolveMqttPort(?int $port): int
    {
        if (is_int($port) && $port > 0) {
            return $port;
        }

        $configuredPort = config('iot.mqtt.port', 1883);

        return is_numeric($configuredPort) ? (int) $configuredPort : 1883;
    }

    private function resolveTopicWithExternalId(Device $device, SchemaVersionTopic $topic): string
    {
        $baseTopic = $device->deviceType?->protocol_config?->getBaseTopic() ?? 'device';
        $identifier = $device->external_id ?: $device->uuid;

        return trim($baseTopic, '/').'/'.$identifier.'/'.$topic->suffix;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function injectCorrelationMeta(array $payload, string $correlationId): array
    {
        if (! (bool) config('iot.device_control.inject_meta_command_id', true)) {
            return $payload;
        }

        $meta = $payload['_meta'] ?? [];

        if (! is_array($meta)) {
            $meta = [];
        }

        $meta['command_id'] = $correlationId;
        $payload['_meta'] = $meta;

        return $payload;
    }

    private function publishToMqttWithRetry(string $mqttTopic, string $payload, string $host, int $port, int $commandLogId): void
    {
        foreach (range(1, self::MAX_MQTT_PUBLISH_ATTEMPTS) as $attempt) {
            try {
                $this->mqttPublisher->publish($mqttTopic, $payload, $host, $port);

                return;
            } catch (\Throwable $exception) {
                $isLastAttempt = $attempt >= self::MAX_MQTT_PUBLISH_ATTEMPTS;
                $shouldRetry = ! $isLastAttempt && $this->shouldRetryPublish($exception);

                if (! $shouldRetry) {
                    throw $exception;
                }

                $this->log()->warning('Transient MQTT publish failure, retrying', [
                    'command_log_id' => $commandLogId,
                    'attempt' => $attempt,
                    'next_attempt' => $attempt + 1,
                    'mqtt_topic' => $mqttTopic,
                    'mqtt_host' => $host,
                    'mqtt_port' => $port,
                    'error' => $exception->getMessage(),
                ]);

                usleep(self::MQTT_PUBLISH_RETRY_DELAY_MICROSECONDS);
            }
        }
    }

    private function shouldRetryPublish(\Throwable $exception): bool
    {
        $errorMessage = strtolower($exception->getMessage());

        return str_contains($errorMessage, 'socket read failed')
            || str_contains($errorMessage, 'connection closed')
            || str_contains($errorMessage, 'timed out')
            || str_contains($errorMessage, 'broken pipe')
            || str_contains($errorMessage, 'reset by peer');
    }

    private function log(): LoggerInterface
    {
        return $this->logManager->channel('device_control');
    }
}
