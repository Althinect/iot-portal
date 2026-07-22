<?php

declare(strict_types=1);

namespace App\Console\Commands\Ingestion;

use App\Domain\Shared\Services\BasisNatsClientHeartbeatProbe;
use App\Domain\Shared\Services\NatsConnectionHeartbeat;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use App\Events\TelemetryIncoming;
use App\Events\TelemetryReceived;
use Basis\Nats\Client;
use Basis\Nats\Configuration;
use Basis\Nats\Message\Payload;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use JsonException;

class ConsumeTelemetryIngestionEvents extends Command
{
    protected $signature = 'ingestion:consume-go-events
                            {--host= : NATS broker host}
                            {--port= : NATS broker port}
                            {--only=all : Event stream to consume (incoming, persisted, or all)}
                            {--incoming-subject= : Subject for Go incoming telemetry events}
                            {--persisted-subject= : Subject for Go persisted telemetry events}';

    protected $description = 'Consume Go telemetry ingestion events and dispatch Laravel side effects.';

    public function handle(BasisNatsClientHeartbeatProbe $heartbeatProbe): int
    {
        $eventMode = $this->resolveEventMode();

        if ($eventMode === null) {
            $this->error('The --only option must be incoming, persisted, or all.');

            return self::INVALID;
        }

        $configuration = new Configuration(
            host: $this->resolveHost(),
            port: $this->resolvePort(),
            timeout: $this->resolveTimeout(),
        );
        $heartbeat = new NatsConnectionHeartbeat($this->resolveHealthCheckInterval());

        while (true) { /** @phpstan-ignore while.alwaysTrue */
            try {
                $client = new Client($configuration);
                $lastActivityAt = microtime(true);

                if ($this->consumesIncomingEvents($eventMode)) {
                    $client->subscribe($this->resolveIncomingSubject(), function (Payload $payload) use (&$lastActivityAt): void {
                        $lastActivityAt = microtime(true);
                        $this->handleIncomingPayload($payload);
                    });
                }

                if ($this->consumesPersistedEvents($eventMode)) {
                    $client->subscribe($this->resolvePersistedSubject(), function (Payload $payload) use (&$lastActivityAt): void {
                        $lastActivityAt = microtime(true);
                        $this->handlePersistedPayload($payload);
                    });
                }

                $this->info("Consuming Go {$eventMode} telemetry ingestion events.");
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
                $this->error("Connection error: {$exception->getMessage()}");
                sleep(1);
            }
        }

        /** @phpstan-ignore deadCode.unreachable */
        return self::SUCCESS;
    }

    private function handleIncomingPayload(Payload $payload): void
    {
        $data = $this->decodePayload($payload);
        if ($data === null) {
            return;
        }

        $topic = $data['topic'] ?? null;
        $body = $data['payload'] ?? null;

        if (! is_string($topic) || ! is_array($body)) {
            return;
        }

        event(new TelemetryIncoming(
            topic: $topic,
            deviceUuid: is_string($data['device_uuid'] ?? null) ? $data['device_uuid'] : null,
            deviceExternalId: is_string($data['device_external_id'] ?? null) ? $data['device_external_id'] : null,
            payload: $body,
            receivedAt: $this->parseTimestamp($data['received_at'] ?? null),
        ));
    }

    private function handlePersistedPayload(Payload $payload): void
    {
        $data = $this->decodePayload($payload);
        if ($data === null) {
            return;
        }

        $telemetryLogId = $data['telemetry_log_id'] ?? null;
        if (! is_string($telemetryLogId) || trim($telemetryLogId) === '') {
            return;
        }

        $telemetryLog = DeviceTelemetryLog::query()->whereKey($telemetryLogId)->first();

        if (! $telemetryLog instanceof DeviceTelemetryLog) {
            $this->warn("Telemetry log [{$telemetryLogId}] not found for Go ingestion side effects.");

            return;
        }

        event(new TelemetryReceived((string) $telemetryLog->getKey()));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodePayload(Payload $payload): ?array
    {
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode((string) $payload->body, true, 512, JSON_THROW_ON_ERROR);

            return $decoded;
        } catch (JsonException $exception) {
            $this->warn("Invalid Go ingestion event payload on [{$payload->subject}]: {$exception->getMessage()}");

            return null;
        }
    }

    private function parseTimestamp(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveHost(): string
    {
        $option = $this->option('host');

        return is_string($option) && trim($option) !== ''
            ? trim($option)
            : $this->resolveStringConfig('ingestion.nats.host', '127.0.0.1');
    }

    private function resolvePort(): int
    {
        $option = $this->option('port');

        if (is_numeric($option)) {
            return (int) $option;
        }

        return $this->resolveIntConfig('ingestion.nats.port', 4222);
    }

    private function resolveTimeout(): int
    {
        return $this->resolveIntConfig('ingestion.nats.timeout', 5);
    }

    private function resolveHealthCheckInterval(): int
    {
        return max(1, $this->resolveIntConfig('ingestion.nats.health_check_seconds', 15));
    }

    private function resolveEventMode(): ?string
    {
        $option = $this->option('only');

        if (! is_string($option) || trim($option) === '') {
            return 'all';
        }

        $eventMode = strtolower(trim($option));

        return in_array($eventMode, ['incoming', 'persisted', 'all'], true) ? $eventMode : null;
    }

    private function consumesIncomingEvents(string $eventMode): bool
    {
        return in_array($eventMode, ['incoming', 'all'], true);
    }

    private function consumesPersistedEvents(string $eventMode): bool
    {
        return in_array($eventMode, ['persisted', 'all'], true);
    }

    private function resolveIncomingSubject(): string
    {
        $option = $this->option('incoming-subject');

        return is_string($option) && trim($option) !== ''
            ? trim($option)
            : $this->resolveStringConfig('ingestion.go_events.incoming_subject', 'iot.v1.ingestion.incoming');
    }

    private function resolvePersistedSubject(): string
    {
        $option = $this->option('persisted-subject');

        return is_string($option) && trim($option) !== ''
            ? trim($option)
            : $this->resolveStringConfig('ingestion.go_events.persisted_subject', 'iot.v1.ingestion.persisted');
    }

    private function resolveStringConfig(string $key, string $default): string
    {
        $value = config($key, $default);

        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    private function resolveIntConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
