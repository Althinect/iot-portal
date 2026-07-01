<?php

declare(strict_types=1);

namespace App\Domain\IoTDashboard\Widgets\StenterUtilization;

use App\Domain\IoTDashboard\Application\DashboardHistoryRange;
use App\Domain\IoTDashboard\Contracts\WidgetConfig;
use App\Domain\IoTDashboard\Contracts\WidgetSnapshotResolver;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class StenterUtilizationSnapshotResolver implements WidgetSnapshotResolver
{
    /**
     * @return array<string, mixed>
     */
    public function resolve(
        IoTDashboardWidget $widget,
        WidgetConfig $config,
        ?DashboardHistoryRange $historyRange = null,
    ): array {
        if (! $config instanceof StenterUtilizationConfig) {
            throw new InvalidArgumentException('Stenter utilization widgets require StenterUtilizationConfig.');
        }

        $now = CarbonImmutable::now('UTC');
        $sources = $config->sources();
        $currentShift = $this->currentShiftInterval($config->shifts(), $now);
        $previousShift = $this->previousShiftInterval($config->shifts(), $currentShift['start_at']);
        $currentShiftStartAt = CarbonImmutable::parse($currentShift['start_at'], 'UTC');
        $currentShiftEndAt = CarbonImmutable::parse($currentShift['end_at'], 'UTC');
        $effectiveCurrentShiftEndAt = $currentShiftEndAt->lessThan($now) ? $currentShiftEndAt : $now;
        $previousShiftStartAt = CarbonImmutable::parse($previousShift['start_at'], 'UTC');
        $previousShiftEndAt = CarbonImmutable::parse($previousShift['end_at'], 'UTC');
        $statusSource = $sources['status'];
        $lengthSource = $sources['length'];
        $currentShiftDurations = $statusSource === null
            ? $this->emptyDurations()
            : $this->statusDurations($statusSource, $currentShiftStartAt, $effectiveCurrentShiftEndAt);
        $lastHourStart = $now->subHour();

        return [
            'widget_id' => (int) $widget->id,
            'generated_at' => $now->toIso8601String(),
            'device_connection_state' => $widget->device?->effectiveConnectionState(),
            'device_last_seen_at' => $widget->device?->lastSeenAt()?->toIso8601String(),
            'card' => [
                'percentage_thresholds' => $config->percentageThresholds(),
                'state' => $this->latestState($statusSource, $config->lookbackMinutes()),
                'current_shift' => [
                    ...$currentShift,
                    'efficiency_percent' => $this->efficiencyPercent($currentShiftDurations),
                    'run_minutes' => round($currentShiftDurations['on'] / 60, 1),
                    'idle_minutes' => round($currentShiftDurations['off'] / 60, 1),
                ],
                'status_segments' => $statusSource === null
                    ? []
                    : $this->statusSegments($statusSource, $lastHourStart, $now),
                'daily_efficiencies' => $statusSource === null
                    ? []
                    : $this->dailyEfficiencies($statusSource, $now),
                'length_counters' => [
                    'month' => $lengthSource === null ? null : $this->counterDelta($lengthSource, $now->startOfMonth(), $now),
                    'current_shift' => $lengthSource === null ? null : $this->counterDelta($lengthSource, $currentShiftStartAt, $effectiveCurrentShiftEndAt),
                    'previous_shift' => $lengthSource === null ? null : $this->counterDelta($lengthSource, $previousShiftStartAt, $previousShiftEndAt),
                ],
            ],
        ];
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
    private function previousShiftInterval(array $shifts, string $currentShiftStartAt): array
    {
        $currentStart = CarbonImmutable::parse($currentShiftStartAt, 'UTC');
        $candidates = [];

        foreach ([$currentStart->subDays(2), $currentStart->subDay(), $currentStart] as $date) {
            foreach ($shifts as $shift) {
                $interval = $this->shiftIntervalForDate($shift, $date);

                if ($interval['end_at']->lessThanOrEqualTo($currentStart)) {
                    $candidates[] = $interval;
                }
            }
        }

        usort(
            $candidates,
            static fn (array $left, array $right): int => $left['end_at']->getTimestamp() <=> $right['end_at']->getTimestamp(),
        );

        return $this->serializeShiftInterval($candidates[array_key_last($candidates)] ?? $this->shiftIntervalForDate($shifts[0], $currentStart->subDay()));
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

    /**
     * @param  array{device_id: int, device_channel_id: int, parameter_key: string}  $source
     * @return array{label: string, value: string|null, color: string, is_running: bool, recorded_at: string|null}
     */
    private function latestState(?array $source, int $lookbackMinutes): array
    {
        if ($source === null) {
            return ['label' => 'No Data', 'value' => null, 'color' => '#64748b', 'is_running' => false, 'recorded_at' => null];
        }

        $log = DeviceTelemetryLog::query()
            ->where('device_id', $source['device_id'])
            ->whereIn('device_channel_id', $this->channelIdsForInput((int) $source['device_channel_id']))
            ->where('recorded_at', '>=', CarbonImmutable::now('UTC')->subMinutes($lookbackMinutes))
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->first(['id', 'recorded_at', 'transformed_values']);

        $state = $this->statusState(data_get($log?->transformed_values, $source['parameter_key']));

        if ($state === 'on') {
            return ['label' => 'Running with Fabric', 'value' => '0', 'color' => '#009000', 'is_running' => true, 'recorded_at' => $log?->recorded_at?->toIso8601String()];
        }

        if ($state === 'off') {
            return ['label' => 'Idle', 'value' => '1', 'color' => '#8f1414', 'is_running' => false, 'recorded_at' => $log?->recorded_at?->toIso8601String()];
        }

        return ['label' => 'No Data', 'value' => null, 'color' => '#64748b', 'is_running' => false, 'recorded_at' => null];
    }

    /**
     * @param  array{device_id: int, device_channel_id: int, parameter_key: string}  $source
     * @return array<int, array{state: string, start_at: string, end_at: string, start_percent: float, width_percent: float}>
     */
    private function statusSegments(array $source, CarbonImmutable $startAt, CarbonImmutable $endAt): array
    {
        $durations = $this->statusDurations($source, $startAt, $endAt, true);

        return $durations['segments'];
    }

    /**
     * @param  array{device_id: int, device_channel_id: int, parameter_key: string}  $source
     * @return array{on: int, off: int, unknown: int, segments: array<int, array{state: string, start_at: string, end_at: string, start_percent: float, width_percent: float}>}
     */
    private function statusDurations(array $source, CarbonImmutable $startAt, CarbonImmutable $endAt, bool $includeSegments = false): array
    {
        if ($endAt->lessThanOrEqualTo($startAt)) {
            return $this->emptyDurations();
        }

        $logs = $this->statusLogs($source, $startAt, $endAt);
        $cursor = $startAt;
        $state = null;
        $durations = $this->emptyDurations();
        $windowSeconds = max(1, $endAt->getTimestamp() - $startAt->getTimestamp());

        foreach ($logs as $log) {
            $recordedAt = CarbonImmutable::parse($log->recorded_at, 'UTC');

            if ($recordedAt->lessThanOrEqualTo($startAt)) {
                $state = $this->statusState(data_get($log->transformed_values, $source['parameter_key']));

                continue;
            }

            if ($recordedAt->greaterThan($endAt)) {
                break;
            }

            $this->addDuration($durations, $state, $cursor, $recordedAt, $startAt, $windowSeconds, $includeSegments);
            $state = $this->statusState(data_get($log->transformed_values, $source['parameter_key']));
            $cursor = $recordedAt;
        }

        $this->addDuration($durations, $state, $cursor, $endAt, $startAt, $windowSeconds, $includeSegments);

        return $durations;
    }

    /**
     * @param  array{on: int, off: int, unknown: int, segments: array<int, array{state: string, start_at: string, end_at: string, start_percent: float, width_percent: float}>}  $durations
     */
    private function addDuration(array &$durations, ?string $state, CarbonImmutable $startAt, CarbonImmutable $endAt, CarbonImmutable $windowStartAt, int $windowSeconds, bool $includeSegments): void
    {
        if ($endAt->lessThanOrEqualTo($startAt)) {
            return;
        }

        $seconds = $endAt->getTimestamp() - $startAt->getTimestamp();
        $durationKey = in_array($state, ['on', 'off'], true) ? $state : 'unknown';
        $durations[$durationKey] += $seconds;

        if (! $includeSegments || ! in_array($state, ['on', 'off'], true)) {
            return;
        }

        $durations['segments'][] = [
            'state' => $state,
            'start_at' => $startAt->toIso8601String(),
            'end_at' => $endAt->toIso8601String(),
            'start_percent' => round((($startAt->getTimestamp() - $windowStartAt->getTimestamp()) / $windowSeconds) * 100, 3),
            'width_percent' => round(($seconds / $windowSeconds) * 100, 3),
        ];
    }

    /**
     * @param  array{device_id: int, device_channel_id: int, parameter_key: string}  $source
     * @return Collection<int, DeviceTelemetryLog>
     */
    private function statusLogs(array $source, CarbonImmutable $startAt, CarbonImmutable $endAt): Collection
    {
        $previousLog = DeviceTelemetryLog::query()
            ->where('device_id', $source['device_id'])
            ->whereIn('device_channel_id', $this->channelIdsForInput((int) $source['device_channel_id']))
            ->where('recorded_at', '<=', $startAt)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->first(['id', 'recorded_at', 'transformed_values']);

        $logs = DeviceTelemetryLog::query()
            ->where('device_id', $source['device_id'])
            ->whereIn('device_channel_id', $this->channelIdsForInput((int) $source['device_channel_id']))
            ->where('recorded_at', '>', $startAt)
            ->where('recorded_at', '<=', $endAt)
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get(['id', 'recorded_at', 'transformed_values']);

        return $previousLog instanceof DeviceTelemetryLog
            ? collect([$previousLog])->merge($logs)->values()
            : $logs;
    }

    /**
     * @param  array{device_id: int, device_channel_id: int, parameter_key: string}  $source
     * @return array<int, array{label: string, efficiency_percent: float|null}>
     */
    private function dailyEfficiencies(array $source, CarbonImmutable $now): array
    {
        $days = [];

        for ($dayOffset = 3; $dayOffset >= 1; $dayOffset--) {
            $dayStart = $now->subDays($dayOffset)->startOfDay();
            $dayEnd = $dayStart->endOfDay();

            $days[] = [
                'label' => $dayStart->format('M d'),
                'efficiency_percent' => $this->efficiencyPercent($this->statusDurations($source, $dayStart, $dayEnd)),
            ];
        }

        return $days;
    }

    /**
     * @param  array{device_id: int, device_channel_id: int, parameter_key: string}  $source
     * @return array{value: float, unit: string}|null
     */
    private function counterDelta(array $source, CarbonImmutable $startAt, CarbonImmutable $endAt): ?array
    {
        if ($endAt->lessThanOrEqualTo($startAt)) {
            return null;
        }

        $firstLog = DeviceTelemetryLog::query()
            ->where('device_id', $source['device_id'])
            ->whereIn('device_channel_id', $this->channelIdsForInput((int) $source['device_channel_id']))
            ->where('recorded_at', '>=', $startAt)
            ->where('recorded_at', '<=', $endAt)
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->first(['id', 'transformed_values']);
        $lastLog = DeviceTelemetryLog::query()
            ->where('device_id', $source['device_id'])
            ->whereIn('device_channel_id', $this->channelIdsForInput((int) $source['device_channel_id']))
            ->where('recorded_at', '>=', $startAt)
            ->where('recorded_at', '<=', $endAt)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->first(['id', 'transformed_values']);
        $firstValue = $this->numericValue($firstLog?->transformed_values, $source['parameter_key']);
        $lastValue = $this->numericValue($lastLog?->transformed_values, $source['parameter_key']);

        if ($firstValue === null || $lastValue === null) {
            return null;
        }

        return [
            'value' => round(max($lastValue - $firstValue, 0), 1),
            'unit' => 'm',
        ];
    }

    /**
     * @return array<int, int>
     */
    private function channelIdsForInput(int $deviceChannelId): array
    {
        if ($deviceChannelId < 1) {
            return [];
        }

        return [$deviceChannelId];
    }

    private function numericValue(mixed $values, string $parameterKey): ?float
    {
        $value = is_array($values) ? data_get($values, $parameterKey) : null;

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param  array{on: int, off: int, unknown: int, segments: array<int, mixed>}  $durations
     */
    private function efficiencyPercent(array $durations): ?float
    {
        $knownSeconds = $durations['on'] + $durations['off'];

        if ($knownSeconds < 1) {
            return null;
        }

        return round(($durations['on'] / $knownSeconds) * 100, 1);
    }

    /**
     * @return array{on: int, off: int, unknown: int, segments: array<int, array{state: string, start_at: string, end_at: string, start_percent: float, width_percent: float}>}
     */
    private function emptyDurations(): array
    {
        return [
            'on' => 0,
            'off' => 0,
            'unknown' => 0,
            'segments' => [],
        ];
    }

    private function statusState(mixed $value): ?string
    {
        if (is_bool($value)) {
            return $value ? 'off' : 'on';
        }

        if (is_numeric($value)) {
            return ((int) round((float) $value)) === 0 ? 'on' : 'off';
        }

        if (is_string($value) && trim($value) !== '') {
            $normalized = strtolower(trim($value));

            if (in_array($normalized, ['0', 'on', 'running'], true)) {
                return 'on';
            }

            if (in_array($normalized, ['1', 'off', 'idle'], true)) {
                return 'off';
            }
        }

        return null;
    }
}
