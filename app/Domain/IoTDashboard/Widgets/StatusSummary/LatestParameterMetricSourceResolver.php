<?php

declare(strict_types=1);

namespace App\Domain\IoTDashboard\Widgets\StatusSummary;

use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use App\Domain\IoTDashboard\Widgets\StatusSummary\Contracts\MetricSourceResolver;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use Carbon\CarbonImmutable;

final class LatestParameterMetricSourceResolver implements MetricSourceResolver
{
    public function type(): StatusSummaryMetricSourceType
    {
        return StatusSummaryMetricSourceType::LatestParameter;
    }

    public function resolve(
        IoTDashboardWidget $widget,
        array $tile,
        ?DeviceTelemetryLog $latestLog,
    ): array {
        $parameterKey = is_string(data_get($tile, 'source.parameter_key'))
            ? trim((string) data_get($tile, 'source.parameter_key'))
            : '';

        if ($parameterKey === '' || ! $latestLog instanceof DeviceTelemetryLog) {
            return ['value' => null, 'timestamp' => null];
        }

        $value = $this->extractNumericValue($latestLog->transformed_values, $parameterKey);
        $timestamp = $this->normalizeRecordedAt($latestLog->recorded_at);

        return [
            'value' => $value,
            'timestamp' => $timestamp,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $values
     */
    private function extractNumericValue(?array $values, string $parameterKey): int|float|null
    {
        if (! is_array($values)) {
            return null;
        }

        $value = data_get($values, $parameterKey);

        return is_numeric($value) ? $value + 0 : null;
    }

    private function normalizeRecordedAt(mixed $recordedAt): ?CarbonImmutable
    {
        if ($recordedAt instanceof CarbonImmutable) {
            return $recordedAt;
        }

        if ($recordedAt instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($recordedAt);
        }

        if (is_string($recordedAt) && trim($recordedAt) !== '') {
            return CarbonImmutable::parse($recordedAt);
        }

        return null;
    }
}
