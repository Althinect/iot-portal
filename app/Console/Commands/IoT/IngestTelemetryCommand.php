<?php

declare(strict_types=1);

namespace App\Console\Commands\IoT;

use App\Domain\DataIngestion\DTO\IncomingTelemetryEnvelope;
use App\Domain\DataIngestion\Jobs\ProcessInboundTelemetryJob;
use App\Domain\DataIngestion\Services\DeviceSignalBindingResolver;
use App\Domain\DataIngestion\Services\DeviceTelemetryTopicResolver;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\Shared\Services\BasisNatsClientHeartbeatProbe;
use App\Domain\Shared\Services\NatsConnectionHeartbeat;
use App\Events\TelemetryIncoming;
use Basis\Nats\Client;
use Basis\Nats\Configuration;
use Basis\Nats\Message\Payload;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Telescope\Telescope;

class IngestTelemetryCommand extends Command
{
    protected $signature = 'iot:ingest-telemetry
                            {--host= : NATS broker host}
                            {--port= : NATS broker port}
                            {--subject= : NATS subject to subscribe to}
                            {--queue= : Queue name for telemetry processing}';

    protected $description = 'Consume inbound telemetry and dispatch ingestion jobs';

    public function handle(BasisNatsClientHeartbeatProbe $heartbeatProbe): int
    {
        $this->disableTelescopeRecording();

        $host = $this->resolveHost();
        $port = $this->resolvePort();
        $subject = $this->resolveSubject();
        $queue = $this->resolveQueue();
        $queueConnection = $this->resolveQueueConnection();

        $this->info('Starting telemetry ingestion listener...');
        $this->info("Connecting to NATS at {$host}:{$port}");
        $this->info("Listening on subject: {$subject}");
        $this->info("Dispatching to queue connection: {$queueConnection}, queue: {$queue}");

        if ($queueConnection === 'redis' && config('database.redis.client') === 'phpredis' && ! extension_loaded('redis')) {
            $this->warn('Redis queue connection is configured, but phpredis extension is unavailable. Set INGESTION_PIPELINE_QUEUE_CONNECTION=database or install phpredis.');
        }

        $configuration = new Configuration(
            host: $host,
            port: $port,
            timeout: $this->resolveTimeout(),
        );
        /** @var DeviceTelemetryTopicResolver $topicResolver */
        $topicResolver = app(DeviceTelemetryTopicResolver::class);
        /** @var DeviceSignalBindingResolver $bindingResolver */
        $bindingResolver = app(DeviceSignalBindingResolver::class);
        $heartbeat = new NatsConnectionHeartbeat($this->resolveHealthCheckInterval());

        while (true) { /** @phpstan-ignore while.alwaysTrue */
            try {
                $client = new Client($configuration);
                $lastActivityAt = microtime(true);

                $client->subscribe($subject, function (Payload $payload) use ($bindingResolver, $queue, $queueConnection, $topicResolver, &$lastActivityAt): void {
                    $lastActivityAt = microtime(true);
                    $sourceSubject = $payload->subject ?? '';
                    $mqttTopic = str_replace('.', '/', $sourceSubject);

                    if ($this->shouldIgnoreSubject($sourceSubject)) {
                        return;
                    }

                    $resolvedTopic = $topicResolver->resolve($mqttTopic);
                    $supportsBindingTopic = $bindingResolver->supportsTopic($mqttTopic);

                    if ($resolvedTopic === null && ! $supportsBindingTopic) {
                        return;
                    }

                    $decodedPayload = [];

                    try {
                        $decoded = json_decode($payload->body, true, flags: JSON_THROW_ON_ERROR);
                        $decodedPayload = is_array($decoded) ? $decoded : [];
                    } catch (\Throwable) {
                        $decodedPayload = [];
                    }

                    $envelope = new IncomingTelemetryEnvelope(
                        sourceSubject: $sourceSubject,
                        mqttTopic: $mqttTopic,
                        payload: $decodedPayload,
                        deviceUuid: is_string(Arr::get($decodedPayload, '_meta.device_uuid')) ? Arr::get($decodedPayload, '_meta.device_uuid') : null,
                        deviceExternalId: is_string(Arr::get($decodedPayload, '_meta.device_external_id')) ? Arr::get($decodedPayload, '_meta.device_external_id') : null,
                        messageId: is_string($payload->headers['Nats-Msg-Id'] ?? null) ? $payload->headers['Nats-Msg-Id'] : null,
                        receivedAt: now(),
                    );

                    $this
                        ->resolveTelemetryBroadcastEnvelopes($envelope, $resolvedTopic, $supportsBindingTopic, $bindingResolver)
                        ->each(function (IncomingTelemetryEnvelope $broadcastEnvelope): void {
                            event(new TelemetryIncoming(
                                topic: $broadcastEnvelope->mqttTopic,
                                deviceUuid: $broadcastEnvelope->deviceUuid,
                                deviceExternalId: $broadcastEnvelope->deviceExternalId,
                                payload: $broadcastEnvelope->payload,
                                receivedAt: $broadcastEnvelope->resolveReceivedAt(),
                            ));
                        });

                    try {
                        ProcessInboundTelemetryJob::dispatch($envelope->toArray())
                            ->onConnection($queueConnection)
                            ->onQueue($queue);
                    } catch (\Throwable $exception) {
                        $this->error("Queue dispatch failed for {$sourceSubject}: {$exception->getMessage()}");
                    }
                });

                $lastHeartbeatAt = microtime(true);

                while (true) { /** @phpstan-ignore while.alwaysTrue */
                    try {
                        $client->process(1);
                        $lastHeartbeatAt = $heartbeat->maintain(
                            ping: fn (): bool => $heartbeatProbe->ping($client),
                            lastHeartbeatAt: $lastHeartbeatAt,
                            lastActivityAt: $lastActivityAt,
                        );
                    } catch (\Throwable $exception) {
                        if (str_contains($exception->getMessage(), 'No handler')) {
                            usleep(200_000);

                            continue;
                        }

                        throw $exception;
                    }
                }
            } catch (\Throwable $exception) {
                $this->error("Processing error: {$exception->getMessage()}");
                sleep(1);
            }
        }

        /** @phpstan-ignore deadCode.unreachable */
        return self::SUCCESS;
    }

    /**
     * @param  array{device: Device, topic: mixed}|null  $resolvedTopic
     * @return Collection<int, IncomingTelemetryEnvelope>
     */
    private function resolveTelemetryBroadcastEnvelopes(
        IncomingTelemetryEnvelope $envelope,
        ?array $resolvedTopic,
        bool $supportsBindingTopic,
        DeviceSignalBindingResolver $bindingResolver,
    ): Collection {
        if ($supportsBindingTopic) {
            $expandedEnvelopes = $bindingResolver->expand($envelope);

            if ($expandedEnvelopes->isNotEmpty()) {
                return $expandedEnvelopes;
            }
        }

        return collect([
            $this->enrichTelemetryEnvelope($envelope, $resolvedTopic),
        ]);
    }

    /**
     * @param  array{device: Device, topic: mixed}|null  $resolvedTopic
     */
    private function enrichTelemetryEnvelope(IncomingTelemetryEnvelope $envelope, ?array $resolvedTopic): IncomingTelemetryEnvelope
    {
        $device = $resolvedTopic['device'] ?? null;

        return new IncomingTelemetryEnvelope(
            sourceSubject: $envelope->sourceSubject,
            mqttTopic: $envelope->mqttTopic,
            payload: $envelope->payload,
            deviceUuid: $envelope->deviceUuid ?? ($device instanceof Device ? $device->uuid : null),
            deviceExternalId: $envelope->deviceExternalId ?? ($device instanceof Device ? $device->external_id : null),
            messageId: $envelope->messageId,
            receivedAt: $envelope->receivedAt,
        );
    }

    private function resolveHost(): string
    {
        $hostOption = $this->option('host');

        if (is_string($hostOption) && trim($hostOption) !== '') {
            return trim($hostOption);
        }

        return $this->resolveStringConfig('ingestion.nats.host', '127.0.0.1');
    }

    private function resolvePort(): int
    {
        $portOption = $this->option('port');

        if (is_numeric($portOption)) {
            return (int) $portOption;
        }

        return $this->resolveIntConfig('ingestion.nats.port', 4223);
    }

    private function resolveSubject(): string
    {
        $subjectOption = $this->option('subject');

        if (is_string($subjectOption) && trim($subjectOption) !== '') {
            return trim($subjectOption);
        }

        return $this->resolveStringConfig('ingestion.nats.subject', '>');
    }

    private function resolveQueue(): string
    {
        $queueOption = $this->option('queue');

        if (is_string($queueOption) && trim($queueOption) !== '') {
            return trim($queueOption);
        }

        return $this->resolveStringConfig('ingestion.queue', 'ingestion');
    }

    private function resolveQueueConnection(): string
    {
        return $this->resolveStringConfig('ingestion.queue_connection', $this->resolveStringConfig('queue.default', 'database'));
    }

    private function resolveHealthCheckInterval(): int
    {
        $interval = config('ingestion.nats.health_check_seconds', config('iot.nats.health_check_seconds', 15));

        return is_numeric($interval) ? max(1, (int) $interval) : 15;
    }

    private function resolveTimeout(): float
    {
        $timeout = config('ingestion.nats.timeout', config('iot.nats.timeout', 5.0));

        return is_numeric($timeout) && (float) $timeout > 0
            ? (float) $timeout
            : 5.0;
    }

    private function shouldIgnoreSubject(string $subject): bool
    {
        if (trim($subject) === '') {
            return true;
        }

        if (Str::startsWith($subject, ['$JS.', '$KV.', '_INBOX.', '_REQS.'])) {
            return true;
        }

        $analyticsPrefix = $this->resolveStringConfig('ingestion.nats.analytics_subject_prefix', 'iot.v1.analytics');
        $invalidPrefix = $this->resolveStringConfig('ingestion.nats.invalid_subject_prefix', 'iot.v1.invalid');

        return Str::startsWith($subject, [$analyticsPrefix, $invalidPrefix]);
    }

    private function resolveStringConfig(string $key, string $fallback): string
    {
        $value = config($key, $fallback);

        return is_string($value) && trim($value) !== '' ? $value : $fallback;
    }

    private function resolveIntConfig(string $key, int $fallback): int
    {
        $value = config($key, $fallback);

        return is_numeric($value) ? (int) $value : $fallback;
    }

    private function disableTelescopeRecording(): void
    {
        if (class_exists(Telescope::class)) {
            Telescope::stopRecording();
        }
    }
}
