<?php

declare(strict_types=1);

namespace App\Console\Commands\IoT;

use App\Domain\DeviceControl\Services\DeviceFeedbackReconciler;
use App\Domain\Shared\Services\BasisNatsClientHeartbeatProbe;
use App\Domain\Shared\Services\NatsConnectionHeartbeat;
use Basis\Nats\Client;
use Basis\Nats\Configuration;
use Basis\Nats\Message\Payload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use JsonException;
use Laravel\Telescope\Telescope;

class ListenForDeviceStates extends Command
{
    protected $signature = 'iot:listen-for-device-states
                            {--host= : NATS broker host}
                            {--port= : NATS broker port}
                            {--subject= : NATS subject to subscribe to}';

    protected $description = 'Listen for device feedback messages from NATS and reconcile command/state lifecycle';

    public function handle(BasisNatsClientHeartbeatProbe $heartbeatProbe): int
    {
        $this->disableTelescopeRecording();

        $host = $this->resolveHost();
        $port = $this->resolvePort();

        $this->info('Starting device state listener...');
        $this->info("Connecting to NATS at {$host}:{$port}");

        Log::channel('device_control')->info('Device state listener starting', [
            'host' => $host,
            'port' => $port,
        ]);

        $configuration = new Configuration(
            host: $host,
            port: $port,
            timeout: $this->resolveTimeout(),
        );
        /** @var DeviceFeedbackReconciler $reconciler */
        $reconciler = app(DeviceFeedbackReconciler::class);
        $heartbeat = new NatsConnectionHeartbeat($this->resolveHealthCheckInterval());

        $natsSubjects = $this->resolveSubjects();

        $this->info('Listening on: '.implode(', ', $natsSubjects));
        $this->newLine();

        while (true) { /** @phpstan-ignore while.alwaysTrue */
            try {
                $client = new Client($configuration);
                $lastActivityAt = microtime(true);

                foreach ($natsSubjects as $natsSubject) {
                    $client->subscribe($natsSubject, function (Payload $payload, ?string $replyTo) use ($host, $port, $reconciler, &$lastActivityAt): void {
                        try {
                            $lastActivityAt = microtime(true);
                            $subject = $payload->subject ?? '';
                            $body = $payload->body;
                            $mqttTopic = str_replace('.', '/', $subject);

                            $decodedPayload = $this->decodePayload($body);

                            if ($decodedPayload === null) {
                                Log::channel('device_control')->debug('Skipping non-JSON message', [
                                    'subject' => $subject,
                                    'body_length' => strlen($body),
                                ]);

                                return;
                            }

                            Log::channel('device_control')->debug('NATS message received', [
                                'subject' => $subject,
                                'mqtt_topic' => $mqttTopic,
                                'payload' => $decodedPayload,
                            ]);

                            $result = $reconciler->reconcileInboundMessage(
                                mqttTopic: $mqttTopic,
                                payload: $decodedPayload,
                                host: $host,
                                port: $port,
                            );

                            if ($result === null) {
                                return;
                            }

                            Log::channel('device_control')->debug('Message reconciled', [
                                'device_uuid' => $result['device_uuid'],
                                'device_external_id' => $result['device_external_id'],
                                'topic' => $result['topic'],
                                'purpose' => $result['purpose'],
                                'command_log_id' => $result['command_log_id'],
                            ]);
                        } catch (\Throwable $e) {
                            Log::channel('device_control')->error('Listener processing error', [
                                'error' => $e->getMessage(),
                                'trace' => $e->getTraceAsString(),
                            ]);

                            $this->error("  Processing error: {$e->getMessage()}");
                        }
                    });
                }

                $this->info('Device state listener is running. Press Ctrl+C to stop.');
                $this->newLine();

                Log::channel('device_control')->info('Device state listener is running');

                $lastHeartbeatAt = microtime(true);

                while (true) { /** @phpstan-ignore while.alwaysTrue */
                    try {
                        $client->process(1);
                        $lastHeartbeatAt = $heartbeat->maintain(
                            ping: fn (): bool => $heartbeatProbe->ping($client),
                            lastHeartbeatAt: $lastHeartbeatAt,
                            lastActivityAt: $lastActivityAt,
                        );
                    } catch (\Throwable $e) {
                        if (str_contains($e->getMessage(), 'No handler')) {
                            usleep(200_000);

                            continue;
                        }

                        throw $e;
                    }
                }
            } catch (\Throwable $e) {
                Log::channel('device_control')->warning('Listener process loop error', [
                    'error' => $e->getMessage(),
                ]);

                sleep(1);
            }
        }
    }

    private function resolveHost(): string
    {
        $hostOption = $this->option('host');

        if (is_string($hostOption) && trim($hostOption) !== '') {
            return trim($hostOption);
        }

        $host = config('iot.nats.host', '127.0.0.1');

        return is_string($host) && trim($host) !== '' ? trim($host) : '127.0.0.1';
    }

    private function resolvePort(): int
    {
        $portOption = $this->option('port');

        if (is_numeric($portOption)) {
            return (int) $portOption;
        }

        $port = config('iot.nats.port', 4223);

        return is_numeric($port) ? (int) $port : 4223;
    }

    /**
     * @return list<string>
     */
    private function resolveSubjects(): array
    {
        $subjectOption = $this->option('subject');

        if (is_string($subjectOption) && trim($subjectOption) !== '') {
            return $this->normalizeSubjects($subjectOption);
        }

        $subject = config('iot.device_control.state_subject', 'devices.*.state,devices.*.*.state,devices.*.*.*.state');

        return is_string($subject)
            ? $this->normalizeSubjects($subject)
            : ['devices.*.state', 'devices.*.*.state', 'devices.*.*.*.state'];
    }

    /**
     * @return list<string>
     */
    private function normalizeSubjects(string $subjects): array
    {
        $normalizedSubjects = collect(explode(',', $subjects))
            ->map(fn (string $subject): string => trim($subject))
            ->filter(fn (string $subject): bool => $subject !== '')
            ->values()
            ->all();

        return $normalizedSubjects === []
            ? ['devices.*.state', 'devices.*.*.state', 'devices.*.*.*.state']
            : $normalizedSubjects;
    }

    private function resolveHealthCheckInterval(): int
    {
        $interval = config('iot.nats.health_check_seconds', 15);

        return is_numeric($interval) ? max(1, (int) $interval) : 15;
    }

    private function resolveTimeout(): float
    {
        $timeout = config('iot.nats.timeout', 5.0);

        return is_numeric($timeout) && (float) $timeout > 0
            ? (float) $timeout
            : 5.0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodePayload(string $body): ?array
    {
        if (trim($body) === '') {
            return [];
        }

        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function disableTelescopeRecording(): void
    {
        if (class_exists(Telescope::class)) {
            Telescope::stopRecording();
        }
    }
}
