<?php

declare(strict_types=1);

namespace App\Domain\IoTDashboard\Widgets\SteamMeter;

use App\Domain\IoTDashboard\Application\DashboardHistoryRange;
use App\Domain\IoTDashboard\Contracts\WidgetConfig;
use App\Domain\IoTDashboard\Contracts\WidgetSnapshotResolver;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use App\Domain\Telemetry\Services\TelemetryQueryService;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class SteamMeterSnapshotResolver implements WidgetSnapshotResolver
{
    public function __construct(
        private readonly TelemetryQueryService $telemetryQuery,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function resolve(
        IoTDashboardWidget $widget,
        WidgetConfig $config,
        ?DashboardHistoryRange $historyRange = null,
    ): array {
        if (! $config instanceof SteamMeterConfig) {
            throw new InvalidArgumentException('Steam meter widgets require SteamMeterConfig.');
        }

        $now = CarbonImmutable::now('UTC');
        $sources = $config->sources();
        $flowSource = $sources['flow'];
        $totalSource = $sources['total'];
        $latestLog = $totalSource === null ? null : $this->latestLog($totalSource, $config->lookbackMinutes());
        $latestTotalKg = $this->valueFromLog($latestLog, $totalSource['parameter_key'] ?? '');
        $currentShift = $this->currentShiftInterval($config->shifts(), $now);
        $previousShift = $this->previousShiftInterval($config->shifts(), $now);

        return [
            'widget_id' => (int) $widget->id,
            'generated_at' => $now->toIso8601String(),
            'device_connection_state' => $widget->device?->effectiveConnectionState(),
            'device_last_seen_at' => $widget->device?->lastSeenAt()?->toIso8601String(),
            'card' => [
                'recorded_at' => $latestLog?->recorded_at?->toIso8601String(),
                'total_tons' => $latestTotalKg === null ? null : round($latestTotalKg / 1000),
                'current_flow_rate' => $flowSource === null ? null : $this->latestValue($flowSource, $config->lookbackMinutes()),
                'monthly_kg' => $totalSource === null ? null : $this->counterDelta($totalSource, $now->startOfMonth(), $now),
                'current_shift' => [
                    ...$currentShift,
                    'kg' => $totalSource === null ? null : $this->counterDelta(
                        $totalSource,
                        CarbonImmutable::parse($currentShift['start_at'], 'UTC'),
                        CarbonImmutable::parse($currentShift['end_at'], 'UTC')->lessThan($now) ? CarbonImmutable::parse($currentShift['end_at'], 'UTC') : $now,
                    ),
                ],
                'previous_shift' => [
                    ...$previousShift,
                    'kg' => $totalSource === null ? null : $this->counterDelta(
                        $totalSource,
                        CarbonImmutable::parse($previousShift['start_at'], 'UTC'),
                        CarbonImmutable::parse($previousShift['end_at'], 'UTC'),
                    ),
                ],
            ],
        ];
    }

    /**
     * @param  array{device_id: int, schema_version_topic_id: int, parameter_key: string}  $source
     */
    private function latestValue(array $source, int $lookbackMinutes): int|float|null
    {
        return $this->valueFromLog($this->latestLog($source, $lookbackMinutes), $source['parameter_key']);
    }

    /**
     * @param  array{device_id: int, schema_version_topic_id: int, parameter_key: string}  $source
     */
    private function latestLog(array $source, int $lookbackMinutes): ?DeviceTelemetryLog
    {
        return $this->telemetryQuery->latestLog(
            deviceId: $source['device_id'],
            schemaVersionTopicId: $source['schema_version_topic_id'],
            lookbackMinutes: $lookbackMinutes,
        );
    }

    private function valueFromLog(?DeviceTelemetryLog $log, string $parameterKey): int|float|null
    {
        $value = data_get($log?->transformed_values, $parameterKey);

        return is_numeric($value) ? $value + 0 : null;
    }

    /**
     * @param  array{device_id: int, schema_version_topic_id: int, parameter_key: string}  $source
     */
    private function counterDelta(array $source, CarbonImmutable $startAt, CarbonImmutable $endAt): ?float
    {
        return $this->telemetryQuery->counterDelta(
            deviceId: $source['device_id'],
            schemaVersionTopicId: $source['schema_version_topic_id'],
            parameterKey: $source['parameter_key'],
            startAt: $startAt,
            endAt: $endAt,
        );
    }

    /**
     * @param  array<int, array{label: string, start_time: string, end_time: string}>  $shifts
     * @return array{label: string, start_at: string, end_at: string, start_time: string, end_time: string}
     */
    private function currentShiftInterval(array $shifts, CarbonImmutable $now): array
    {
        foreach ([$now->subDay(), $now] as $date) {
            foreach ($shifts as $shift) {
                $interval = $this->shiftIntervalForDate($shift, $date);

                if ($now->greaterThanOrEqualTo($interval['start_at']) && $now->lessThan($interval['end_at'])) {
                    return $this->serializeShiftInterval($interval);
                }
            }
        }

        return $this->serializeShiftInterval($this->shiftIntervalForDate($shifts[0], $now));
    }

    /**
     * @param  array<int, array{label: string, start_time: string, end_time: string}>  $shifts
     * @return array{label: string, start_at: string, end_at: string, start_time: string, end_time: string}
     */
    private function previousShiftInterval(array $shifts, CarbonImmutable $now): array
    {
        $current = $this->currentShiftInterval($shifts, $now);
        $currentStartAt = CarbonImmutable::parse($current['start_at'], 'UTC');
        $previous = null;

        foreach ([$now->subDays(2), $now->subDay(), $now] as $date) {
            foreach ($shifts as $shift) {
                $interval = $this->shiftIntervalForDate($shift, $date);

                if ($interval['end_at']->lessThanOrEqualTo($currentStartAt) && ($previous === null || $interval['end_at']->greaterThan($previous['end_at']))) {
                    $previous = $interval;
                }
            }
        }

        return $this->serializeShiftInterval($previous ?? $this->shiftIntervalForDate($shifts[0], $now->subDay()));
    }

    /**
     * @param  array{label: string, start_time: string, end_time: string}  $shift
     * @return array{label: string, start_at: CarbonImmutable, end_at: CarbonImmutable, start_time: string, end_time: string}
     */
    private function shiftIntervalForDate(array $shift, CarbonImmutable $date): array
    {
        $startAt = $this->timeOnDate($date, $shift['start_time']);
        $endAt = $this->timeOnDate($date, $shift['end_time']);

        if ($endAt->lessThanOrEqualTo($startAt)) {
            $endAt = $endAt->addDay();
        }

        return [
            'label' => $shift['label'],
            'start_at' => $startAt,
            'end_at' => $endAt,
            'start_time' => $shift['start_time'],
            'end_time' => $shift['end_time'],
        ];
    }

    private function timeOnDate(CarbonImmutable $date, string $time): CarbonImmutable
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $date->setTimezone('UTC')->setTime($hour, $minute)->startOfMinute();
    }

    /**
     * @param  array{label: string, start_at: CarbonImmutable, end_at: CarbonImmutable, start_time: string, end_time: string}  $interval
     * @return array{label: string, start_at: string, end_at: string, start_time: string, end_time: string}
     */
    private function serializeShiftInterval(array $interval): array
    {
        return [
            'label' => $interval['label'],
            'start_at' => $interval['start_at']->toIso8601String(),
            'end_at' => $interval['end_at']->toIso8601String(),
            'start_time' => $interval['start_time'],
            'end_time' => $interval['end_time'],
        ];
    }
}
