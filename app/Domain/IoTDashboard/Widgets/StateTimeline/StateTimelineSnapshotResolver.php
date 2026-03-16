<?php

declare(strict_types=1);

namespace App\Domain\IoTDashboard\Widgets\StateTimeline;

use App\Domain\IoTDashboard\Application\DashboardHistoryRange;
use App\Domain\IoTDashboard\Contracts\WidgetConfig;
use App\Domain\IoTDashboard\Contracts\WidgetSnapshotResolver;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use App\Domain\IoTDashboard\Widgets\Concerns\NormalizesWidgetConfig;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class StateTimelineSnapshotResolver implements WidgetSnapshotResolver
{
    use NormalizesWidgetConfig;

    /**
     * @return array<string, mixed>
     */
    public function resolve(
        IoTDashboardWidget $widget,
        WidgetConfig $config,
        ?DashboardHistoryRange $historyRange = null,
    ): array {
        if (! $config instanceof StateTimelineConfig) {
            throw new InvalidArgumentException('State timeline widgets require StateTimelineConfig.');
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

            foreach ($logs as $log) {
                $resolvedPoint = $this->resolveStatePoint(
                    recordedAt: $log->recorded_at,
                    values: $log->transformed_values,
                    parameterKey: $seriesConfiguration['key'],
                    stateMappings: $config->stateMappings(),
                );

                if ($resolvedPoint === null) {
                    continue;
                }

                $points[] = $resolvedPoint;
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
     * @param  array<int, array{value: string, label: string, color: string}>  $stateMappings
     * @return array{timestamp: string, value: int, raw_value: bool|int|float|string|null, state_key: string, state_label: string, state_color: string}|null
     */
    private function resolveStatePoint(
        mixed $recordedAt,
        ?array $values,
        string $parameterKey,
        array $stateMappings,
    ): ?array {
        if (! is_array($values)) {
            return null;
        }

        $rawValue = data_get($values, $parameterKey);
        $timestamp = $this->normalizeRecordedAt($recordedAt)?->toIso8601String();

        if (! is_string($timestamp)) {
            return null;
        }

        $stateKey = self::normalizeStateValueKey($rawValue);

        if ($stateKey === '') {
            return null;
        }

        $fallbackLabel = is_scalar($rawValue) || $rawValue instanceof \Stringable
            ? (string) $rawValue
            : 'Unknown';

        $resolvedIndex = 0;
        $resolvedLabel = $fallbackLabel === '' ? 'Unknown' : $fallbackLabel;
        $resolvedColor = '#64748b';

        foreach (array_values($stateMappings) as $index => $stateMapping) {
            if ($stateMapping['value'] !== $stateKey) {
                continue;
            }

            $resolvedIndex = $index + 1;
            $resolvedLabel = $stateMapping['label'];
            $resolvedColor = $stateMapping['color'];
            break;
        }

        return [
            'timestamp' => $timestamp,
            'value' => $resolvedIndex,
            'raw_value' => is_bool($rawValue) || is_int($rawValue) || is_float($rawValue) || is_string($rawValue)
                ? $rawValue
                : null,
            'state_key' => $stateKey,
            'state_label' => $resolvedLabel,
            'state_color' => $resolvedColor,
        ];
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
