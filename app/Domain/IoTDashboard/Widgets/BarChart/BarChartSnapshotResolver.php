<?php

declare(strict_types=1);

namespace App\Domain\IoTDashboard\Widgets\BarChart;

use App\Domain\IoTDashboard\Application\DashboardHistoryRange;
use App\Domain\IoTDashboard\Contracts\WidgetConfig;
use App\Domain\IoTDashboard\Contracts\WidgetSnapshotResolver;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class BarChartSnapshotResolver implements WidgetSnapshotResolver
{
    /**
     * @return array<string, mixed>
     */
    public function resolve(
        IoTDashboardWidget $widget,
        WidgetConfig $config,
        ?DashboardHistoryRange $historyRange = null,
    ): array {
        if (! $config instanceof BarChartConfig) {
            throw new InvalidArgumentException('Bar chart widgets require BarChartConfig.');
        }

        $deviceId = is_numeric($widget->device_id) ? (int) $widget->device_id : null;
        $logs = $deviceId === null
            ? collect()
            : $this->fetchTelemetryLogs(
                schemaVersionTopicId: (int) $widget->schema_version_topic_id,
                deviceId: $deviceId,
                lookbackMinutes: $config->lookbackMinutes(),
                historyRange: $historyRange,
            );

        $series = [];

        foreach ($config->series() as $seriesConfiguration) {
            $series[] = [
                'key' => $seriesConfiguration['key'],
                'label' => $seriesConfiguration['label'],
                'color' => $seriesConfiguration['color'],
                'points' => $this->buildConsumptionBuckets(
                    logs: $logs,
                    parameterKey: $seriesConfiguration['key'],
                    interval: $config->barInterval(),
                    maxPoints: $config->maxPoints(),
                ),
            ];
        }

        return [
            'widget_id' => (int) $widget->id,
            'generated_at' => now()->toIso8601String(),
            'interval' => $config->barInterval()->value,
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
            ->orderBy('recorded_at')
            ->get(['id', 'recorded_at', 'transformed_values']);
    }

    /**
     * @param  Collection<int, DeviceTelemetryLog>  $logs
     * @return array<int, array{timestamp: string, value: int|float}>
     */
    private function buildConsumptionBuckets(
        Collection $logs,
        string $parameterKey,
        BarInterval $interval,
        int $maxPoints,
    ): array {
        /** @var array<string, array{timestamp: string, start: float, end: float}> $buckets */
        $buckets = [];

        foreach ($logs as $log) {
            $value = $this->extractNumericValue($log->transformed_values, $parameterKey);

            if ($value === null) {
                continue;
            }

            $recordedAt = $this->normalizeRecordedAt($log->recorded_at);

            if (! $recordedAt instanceof Carbon) {
                continue;
            }

            $bucketStart = $this->resolveBucketStart($recordedAt, $interval)->toIso8601String();

            if (! array_key_exists($bucketStart, $buckets)) {
                $buckets[$bucketStart] = [
                    'timestamp' => $bucketStart,
                    'start' => $value,
                    'end' => $value,
                ];

                continue;
            }

            $buckets[$bucketStart]['end'] = $value;
        }

        if ($buckets === []) {
            return [];
        }

        ksort($buckets);

        $points = array_map(
            static fn (array $bucket): array => [
                'timestamp' => $bucket['timestamp'],
                'value' => round(max($bucket['end'] - $bucket['start'], 0), 3),
            ],
            array_values($buckets),
        );

        if ($maxPoints > 0 && count($points) > $maxPoints) {
            $points = array_slice($points, -$maxPoints);
        }

        return $points;
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

    private function resolveBucketStart(Carbon $recordedAt, BarInterval $interval): Carbon
    {
        $bucketStart = $recordedAt->copy();

        return match ($interval) {
            BarInterval::Hourly => $bucketStart->startOfHour(),
            BarInterval::Daily => $bucketStart->startOfDay(),
        };
    }

    private function extractNumericValue(mixed $values, string $parameterKey): ?float
    {
        if (! is_array($values)) {
            return null;
        }

        $value = data_get($values, $parameterKey);

        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }
}
