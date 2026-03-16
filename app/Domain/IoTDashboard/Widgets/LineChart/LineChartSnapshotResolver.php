<?php

declare(strict_types=1);

namespace App\Domain\IoTDashboard\Widgets\LineChart;

use App\Domain\IoTDashboard\Application\DashboardHistoryRange;
use App\Domain\IoTDashboard\Contracts\WidgetConfig;
use App\Domain\IoTDashboard\Contracts\WidgetSnapshotResolver;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class LineChartSnapshotResolver implements WidgetSnapshotResolver
{
    /**
     * @return array<string, mixed>
     */
    public function resolve(
        IoTDashboardWidget $widget,
        WidgetConfig $config,
        ?DashboardHistoryRange $historyRange = null,
    ): array {
        if (! $config instanceof LineChartConfig) {
            throw new InvalidArgumentException('Line chart widgets require LineChartConfig.');
        }

        $deviceId = is_numeric($widget->device_id) ? (int) $widget->device_id : null;
        $logs = $deviceId === null
            ? collect()
            : $this->fetchTelemetryLogs(
                schemaVersionTopicId: (int) $widget->schema_version_topic_id,
                deviceId: $deviceId,
                lookbackMinutes: $config->lookbackMinutes(),
                maxPoints: $config->maxPoints(),
                historyRange: $historyRange,
            );

        $series = [];

        foreach ($config->series() as $seriesConfiguration) {
            $points = [];
            $key = $seriesConfiguration['key'];

            foreach ($logs as $log) {
                $value = $this->extractNumericValue($log->transformed_values, $key);

                if ($value === null) {
                    continue;
                }

                $timestamp = $log->recorded_at?->toIso8601String();

                if (! is_string($timestamp)) {
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
        ?DashboardHistoryRange $historyRange,
    ): Collection {
        $query = DeviceTelemetryLog::query()
            ->where('schema_version_topic_id', $schemaVersionTopicId)
            ->where('device_id', $deviceId);

        if ($historyRange instanceof DashboardHistoryRange) {
            $query
                ->where('recorded_at', '>=', $historyRange->fromAt())
                ->where('recorded_at', '<=', $historyRange->untilAt());
        } else {
            $query->where('recorded_at', '>=', now()->subMinutes($lookbackMinutes));
        }

        return $query
            ->orderByDesc('recorded_at')
            ->limit($maxPoints)
            ->get(['id', 'recorded_at', 'transformed_values'])
            ->sortBy('recorded_at')
            ->values();
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

        if (is_numeric($value)) {
            return $value + 0;
        }

        return null;
    }
}
