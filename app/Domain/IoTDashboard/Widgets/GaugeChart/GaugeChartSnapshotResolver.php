<?php

declare(strict_types=1);

namespace App\Domain\IoTDashboard\Widgets\GaugeChart;

use App\Domain\IoTDashboard\Application\DashboardHistoryRange;
use App\Domain\IoTDashboard\Contracts\WidgetConfig;
use App\Domain\IoTDashboard\Contracts\WidgetSnapshotResolver;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class GaugeChartSnapshotResolver implements WidgetSnapshotResolver
{
    /**
     * @return array<string, mixed>
     */
    public function resolve(
        IoTDashboardWidget $widget,
        WidgetConfig $config,
        ?DashboardHistoryRange $historyRange = null,
    ): array {
        if (! $config instanceof GaugeChartConfig) {
            throw new InvalidArgumentException('Gauge chart widgets require GaugeChartConfig.');
        }

        $deviceId = is_numeric($widget->device_id) ? (int) $widget->device_id : null;
        $logs = $deviceId === null
            ? collect()
            : $this->fetchTelemetryLogs(
                schemaVersionTopicId: (int) $widget->schema_version_topic_id,
                deviceId: $deviceId,
                lookbackMinutes: $config->lookbackMinutes(),
                maxPoints: $config->maxPoints(),
            );

        $series = [];

        foreach ($config->series() as $seriesConfiguration) {
            $points = [];

            foreach ($logs as $log) {
                $value = $this->extractNumericValue($log->transformed_values, $seriesConfiguration['key']);
                $recordedAt = $this->normalizeRecordedAt($log->recorded_at);
                $timestamp = $recordedAt?->toIso8601String();

                if ($value === null || ! is_string($timestamp)) {
                    continue;
                }

                $points[] = [
                    'timestamp' => $timestamp,
                    'value' => $value,
                ];
            }

            $series[] = [
                'key' => $seriesConfiguration['key'],
                'label' => $seriesConfiguration['label'],
                'color' => $seriesConfiguration['color'],
                'points' => $points,
            ];
        }

        return [
            'widget_id' => (int) $widget->id,
            'generated_at' => now()->toIso8601String(),
            'series' => $series,
        ];
    }

    /**
     * @return Collection<int, DeviceTelemetryLog>
     */
    private function fetchTelemetryLogs(
        int $schemaVersionTopicId,
        int $deviceId,
        int $lookbackMinutes,
        int $maxPoints,
    ): Collection {
        return DeviceTelemetryLog::query()
            ->where('schema_version_topic_id', $schemaVersionTopicId)
            ->where('device_id', $deviceId)
            ->where('recorded_at', '>=', now()->subMinutes($lookbackMinutes))
            ->orderByDesc('recorded_at')
            ->limit($maxPoints)
            ->get(['id', 'recorded_at', 'transformed_values'])
            ->sortBy('recorded_at')
            ->values();
    }

    private function extractNumericValue(mixed $values, string $parameterKey): int|float|null
    {
        if (! is_array($values)) {
            return null;
        }

        $value = data_get($values, $parameterKey);

        if (is_numeric($value)) {
            return $value + 0;
        }

        return null;
    }

    private function normalizeRecordedAt(mixed $recordedAt): ?Carbon
    {
        if ($recordedAt instanceof Carbon) {
            return $recordedAt;
        }

        if (is_string($recordedAt) && trim($recordedAt) !== '') {
            return Carbon::parse($recordedAt);
        }

        return null;
    }
}
