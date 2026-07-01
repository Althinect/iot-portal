<?php

declare(strict_types=1);

namespace App\Domain\IoTDashboard\Widgets\Concerns;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Enums\MetricUnit;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use Carbon\CarbonInterface;
use Illuminate\Support\Number;

trait InterpretsThresholdStatusSnapshot
{
    /**
     * @return array{
     *     current_value: float|null,
     *     current_value_display: string,
     *     current_value_recorded_at: string|null,
     *     last_value: float|null,
     *     last_value_display: string,
     *     last_value_recorded_at: string|null,
     *     last_online_at: string|null,
     *     display_value: float|null,
     *     display_value_display: string,
     *     display_timestamp: string|null
     * }
     */
    protected function resolveThresholdValueSnapshot(
        string $status,
        ?Device $device,
        ?DeviceTelemetryLog $latestRecentLog,
        ?DeviceTelemetryLog $latestAvailableLog,
        ?float $currentValue,
        ?float $lastValue,
        ?string $unit,
    ): array {
        $lastOnlineAt = $this->toIso8601String($device?->lastSeenAt());
        $currentValueRecordedAt = $this->toIso8601String($latestRecentLog?->recorded_at);
        $lastValueRecordedAt = $this->toIso8601String(($latestAvailableLog ?? $latestRecentLog)?->recorded_at);
        $resolvedLastValue = $lastValue ?? $currentValue;
        $displayValue = $status === 'offline'
            ? $resolvedLastValue
            : $currentValue;
        $displayTimestamp = $status === 'offline'
            ? $lastOnlineAt
            : $currentValueRecordedAt;

        return [
            'current_value' => $currentValue,
            'current_value_display' => $currentValue === null ? '—' : $this->formatValue($currentValue, $unit),
            'current_value_recorded_at' => $currentValueRecordedAt,
            'last_value' => $resolvedLastValue,
            'last_value_display' => $resolvedLastValue === null ? '—' : $this->formatValue($resolvedLastValue, $unit),
            'last_value_recorded_at' => $lastValueRecordedAt,
            'last_online_at' => $lastOnlineAt,
            'display_value' => $displayValue,
            'display_value_display' => $displayValue === null ? '—' : $this->formatValue($displayValue, $unit),
            'display_timestamp' => $displayTimestamp,
        ];
    }

    protected function resolveUnitSymbol(?string $unit): ?string
    {
        return match ($unit) {
            MetricUnit::Celsius->value => '°C',
            MetricUnit::Percent->value => '%',
            MetricUnit::Volts->value => 'V',
            MetricUnit::Amperes->value => 'A',
            MetricUnit::KilowattHours->value => 'kWh',
            MetricUnit::Watts->value => 'W',
            MetricUnit::Seconds->value => 's',
            MetricUnit::DecibelMilliwatts->value => 'dBm',
            MetricUnit::RevolutionsPerMinute->value => 'RPM',
            MetricUnit::Litres->value => 'L',
            MetricUnit::CubicMeters->value => 'm³',
            MetricUnit::LitersPerMinute->value => 'L/min',
            default => is_string($unit) && trim($unit) !== '' ? trim($unit) : null,
        };
    }

    protected function formatValue(float $value, ?string $unit = null): string
    {
        $formattedNumber = Number::format($value, maxPrecision: 3);
        $formattedValue = is_string($formattedNumber)
            ? (str_contains($formattedNumber, '.')
                ? rtrim(rtrim($formattedNumber, '0'), '.')
                : $formattedNumber)
            : (string) $value;

        return $unit === null ? $formattedValue : $formattedValue.$unit;
    }

    protected function toIso8601String(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        return null;
    }
}
