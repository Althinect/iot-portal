<?php

declare(strict_types=1);

namespace App\Domain\IoTDashboard\Application;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Publishing\Nats\NatsDeviceStateStore;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Domain\Telemetry\Services\TelemetryQueryService;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

class HotStateLatestTelemetryReader
{
    public function __construct(
        private readonly NatsDeviceStateStore $stateStore,
        private readonly TelemetryQueryService $telemetryQuery,
    ) {}

    public function read(Device $device, SchemaVersionTopic $topic, ?int $lookbackMinutes = null): ?LatestTelemetryState
    {
        $hotState = $this->readHotState($device, $topic, $lookbackMinutes);

        if ($hotState instanceof LatestTelemetryState) {
            return $hotState;
        }

        $latestLog = $this->telemetryQuery->latestLog(
            deviceId: (int) $device->id,
            schemaVersionTopicId: (int) $topic->id,
            lookbackMinutes: $lookbackMinutes,
        );

        return $latestLog === null ? null : LatestTelemetryState::fromTelemetryLog($latestLog);
    }

    private function readHotState(Device $device, SchemaVersionTopic $topic, ?int $lookbackMinutes): ?LatestTelemetryState
    {
        if (! (bool) config('iot_dashboard.hot_state_reads.enabled', false)) {
            return null;
        }

        try {
            $state = $this->stateStore->getStateByTopic(
                deviceUuid: $device->uuid,
                topic: $topic->resolvedTopic($device),
                host: $this->resolveHost(),
                port: $this->resolvePort(),
            );
        } catch (Throwable) {
            return null;
        }

        if (! is_array($state)) {
            return null;
        }

        $payload = $state['payload'] ?? null;
        $values = is_array($payload) && is_array($payload['values'] ?? null)
            ? $payload['values']
            : null;

        if ($values === null) {
            return null;
        }

        /** @var array<string, mixed> $values */
        $recordedAt = $this->parseCarbon($payload['recorded_at'] ?? $state['stored_at'] ?? null);

        if (! $this->isWithinLookback($recordedAt, $lookbackMinutes)) {
            return null;
        }

        return new LatestTelemetryState(
            values: $values,
            recordedAt: $recordedAt,
            telemetryLogId: is_string($payload['telemetry_log_id'] ?? null) ? $payload['telemetry_log_id'] : null,
            ingestionMessageId: is_string($payload['ingestion_message_id'] ?? null) ? $payload['ingestion_message_id'] : null,
            status: is_string($payload['status'] ?? null) ? $payload['status'] : null,
            storedAt: $this->parseCarbon($state['stored_at'] ?? null),
        );
    }

    private function isWithinLookback(?CarbonImmutable $recordedAt, ?int $lookbackMinutes): bool
    {
        if ($lookbackMinutes === null) {
            return true;
        }

        return $recordedAt instanceof CarbonImmutable
            && $recordedAt->greaterThanOrEqualTo(CarbonImmutable::now('UTC')->subMinutes(max(1, $lookbackMinutes)));
    }

    private function resolveHost(): string
    {
        $host = config('ingestion.nats.host', config('iot.nats.host', '127.0.0.1'));

        return is_string($host) && trim($host) !== '' ? trim($host) : '127.0.0.1';
    }

    private function resolvePort(): int
    {
        $port = config('ingestion.nats.port', config('iot.nats.port', 4223));

        return is_numeric($port) ? (int) $port : 4223;
    }

    private function parseCarbon(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (is_string($value) && trim($value) !== '') {
            return CarbonImmutable::parse($value);
        }

        return null;
    }
}
