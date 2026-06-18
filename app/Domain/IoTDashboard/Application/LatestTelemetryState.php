<?php

declare(strict_types=1);

namespace App\Domain\IoTDashboard\Application;

use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use Carbon\CarbonImmutable;
use DateTimeInterface;

final readonly class LatestTelemetryState
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(
        public array $values,
        public ?CarbonImmutable $recordedAt = null,
        public ?string $telemetryLogId = null,
        public ?string $ingestionMessageId = null,
        public ?string $status = null,
        public ?CarbonImmutable $storedAt = null,
    ) {}

    public static function fromTelemetryLog(DeviceTelemetryLog $telemetryLog): self
    {
        return new self(
            values: is_array($telemetryLog->transformed_values) ? $telemetryLog->transformed_values : [],
            recordedAt: self::carbon($telemetryLog->recorded_at),
            telemetryLogId: is_string($telemetryLog->id) ? $telemetryLog->id : null,
        );
    }

    public function value(string $parameterKey): mixed
    {
        return data_get($this->values, $parameterKey);
    }

    public function numericValue(string $parameterKey): int|float|null
    {
        $value = $this->value($parameterKey);

        return is_numeric($value) ? $value + 0 : null;
    }

    private static function carbon(mixed $value): ?CarbonImmutable
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
