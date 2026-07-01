<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Services;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Enums\ParameterCategory;
use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use App\Domain\Reporting\Enums\ReportGrouping;
use App\Domain\Reporting\Enums\ReportRunStatus;
use App\Domain\Reporting\Enums\ReportType;
use App\Domain\Reporting\Models\ReportRun;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ReportGenerationService
{
    public function __construct(
        private readonly ShiftWindowResolver $shiftWindowResolver,
    ) {}

    public function generate(ReportRun $reportRun): ReportRun
    {
        $reportRun->loadMissing(['device:id,name,uuid,device_profile_version_id', 'organization:id,name']);

        $resolvedTimezone = $this->shiftWindowResolver->resolveTimezone($reportRun->timezone);
        $resolvedRange = $this->shiftWindowResolver->normalizeRange(
            fromAt: $reportRun->from_at,
            untilAt: $reportRun->until_at,
            timezone: $resolvedTimezone,
        );

        $fromUtc = $resolvedRange['from_utc'];
        $untilUtc = $resolvedRange['until_utc'];

        $logs = $this->fetchTelemetryLogs($reportRun, $fromUtc, $untilUtc);

        [$header, $rows] = match ($reportRun->type) {
            ReportType::ParameterValues => $this->buildParameterValueReportRows($reportRun, $logs, $resolvedTimezone),
            ReportType::CounterConsumption => $this->buildCounterConsumptionReportRows(
                reportRun: $reportRun,
                logs: $logs,
                timezone: $resolvedTimezone,
                fromUtc: $fromUtc,
                untilUtc: $untilUtc,
            ),
            ReportType::StateUtilization => $this->buildStateUtilizationReportRows(
                reportRun: $reportRun,
                logs: $logs,
                timezone: $resolvedTimezone,
                fromUtc: $fromUtc,
                untilUtc: $untilUtc,
            ),
        };

        if ($rows === []) {
            $reportRun->forceFill([
                'status' => ReportRunStatus::NoData,
                'row_count' => 0,
                'storage_disk' => null,
                'storage_path' => null,
                'file_name' => null,
                'file_size' => null,
                'generated_at' => now(),
                'failed_at' => null,
                'failure_reason' => null,
                'meta' => [
                    'timezone' => $resolvedTimezone,
                    'from_at' => $resolvedRange['from_local']->toIso8601String(),
                    'until_at' => $resolvedRange['until_local']->toIso8601String(),
                    'grouping' => $reportRun->grouping?->value,
                    'shift_schedule' => data_get($reportRun->payload, 'shift_schedule'),
                ],
            ])->save();

            return $reportRun->refresh();
        }

        $csvContent = $this->buildCsvContent($header, $rows);
        $storageDisk = $this->stringConfig('reporting.storage_disk', 'local');
        $storageDirectory = trim($this->stringConfig('reporting.storage_directory', 'reports'), '/');
        $fileName = "report-{$reportRun->id}-{$reportRun->type->value}-".now()->format('Ymd_His').'.csv';
        $storagePath = "{$storageDirectory}/{$fileName}";

        try {
            $written = Storage::disk($storageDisk)->put($storagePath, $csvContent);
        } catch (\Throwable $exception) {
            logger()->error('Report CSV write threw an exception', [
                'report_run_id' => $reportRun->id,
                'disk' => $storageDisk,
                'path' => $storagePath,
                'error' => $exception->getMessage(),
                'exception_class' => $exception::class,
            ]);

            $reportRun->forceFill([
                'status' => ReportRunStatus::Failed,
                'row_count' => count($rows),
                'storage_disk' => null,
                'storage_path' => null,
                'file_name' => null,
                'file_size' => null,
                'generated_at' => now(),
                'failed_at' => now(),
                'failure_reason' => 'Failed to write report to storage: '.$exception->getMessage(),
                'meta' => [
                    'timezone' => $resolvedTimezone,
                    'from_at' => $resolvedRange['from_local']->toIso8601String(),
                    'until_at' => $resolvedRange['until_local']->toIso8601String(),
                    'grouping' => $reportRun->grouping?->value,
                    'shift_schedule' => data_get($reportRun->payload, 'shift_schedule'),
                ],
            ])->save();

            return $reportRun->refresh();
        }

        if (! $written) {
            logger()->warning('Report CSV could not be written to disk', ['report_run_id' => $reportRun->id, 'disk' => $storageDisk, 'path' => $storagePath]);

            $reportRun->forceFill([
                'status' => ReportRunStatus::Failed,
                'row_count' => count($rows),
                'storage_disk' => null,
                'storage_path' => null,
                'file_name' => null,
                'file_size' => null,
                'generated_at' => now(),
                'failed_at' => now(),
                'failure_reason' => sprintf('Failed to write report to disk "%s".', $storageDisk),
                'meta' => [
                    'timezone' => $resolvedTimezone,
                    'from_at' => $resolvedRange['from_local']->toIso8601String(),
                    'until_at' => $resolvedRange['until_local']->toIso8601String(),
                    'grouping' => $reportRun->grouping?->value,
                    'shift_schedule' => data_get($reportRun->payload, 'shift_schedule'),
                ],
            ])->save();

            return $reportRun->refresh();
        }

        $reportRun->forceFill([
            'status' => ReportRunStatus::Completed,
            'row_count' => count($rows),
            'storage_disk' => $storageDisk,
            'storage_path' => $storagePath,
            'file_name' => $fileName,
            'file_size' => strlen($csvContent),
            'generated_at' => now(),
            'failed_at' => null,
            'failure_reason' => null,
            'meta' => [
                'timezone' => $resolvedTimezone,
                'from_at' => $resolvedRange['from_local']->toIso8601String(),
                'until_at' => $resolvedRange['until_local']->toIso8601String(),
                'grouping' => $reportRun->grouping?->value,
                'shift_schedule' => data_get($reportRun->payload, 'shift_schedule'),
            ],
        ])->save();

        return $reportRun->refresh();
    }

    /**
     * @return Collection<int, DeviceTelemetryLog>
     */
    private function fetchTelemetryLogs(
        ReportRun $reportRun,
        CarbonImmutable $fromUtc,
        CarbonImmutable $untilUtc,
    ): Collection {
        return DeviceTelemetryLog::query()
            ->where('device_id', $reportRun->device_id)
            ->where('recorded_at', '>=', $fromUtc)
            ->where('recorded_at', '<', $untilUtc)
            ->orderBy('recorded_at')
            ->get(['id', 'device_id', 'recorded_at', 'transformed_values']);
    }

    /**
     * @param  Collection<int, DeviceTelemetryLog>  $logs
     * @return array{0: array<int, string>, 1: array<int, array<int, mixed>>}
     */
    private function buildParameterValueReportRows(
        ReportRun $reportRun,
        Collection $logs,
        string $timezone,
    ): array {
        $parameterMap = $this->resolveParameterMap($reportRun, null, false);
        $parameterKeys = $this->resolveParameterKeys($reportRun, array_keys($parameterMap));

        $header = array_merge(
            ['recorded_at', 'device_uuid', 'device_name'],
            $parameterKeys,
        );

        $rows = $logs
            ->map(function (DeviceTelemetryLog $log) use ($reportRun, $timezone, $parameterKeys): array {
                $timestamp = CarbonImmutable::instance($log->recorded_at)
                    ->setTimezone($timezone)
                    ->format('Y-m-d H:i:s');
                $device = $reportRun->device;

                $row = [
                    $timestamp,
                    $device instanceof Device ? (string) $device->uuid : '',
                    $device instanceof Device ? (string) $device->name : '',
                ];

                foreach ($parameterKeys as $parameterKey) {
                    $row[] = data_get($log->transformed_values, $parameterKey);
                }

                return $row;
            })
            ->all();

        return [$header, $rows];
    }

    /**
     * @param  Collection<int, DeviceTelemetryLog>  $logs
     * @return array{0: array<int, string>, 1: array<int, array<int, mixed>>}
     */
    private function buildCounterConsumptionReportRows(
        ReportRun $reportRun,
        Collection $logs,
        string $timezone,
        CarbonImmutable $fromUtc,
        CarbonImmutable $untilUtc,
    ): array {
        $grouping = $this->resolveAggregationGrouping($reportRun);
        $windows = $this->resolveAggregationWindows(
            reportRun: $reportRun,
            grouping: $grouping,
            timezone: $timezone,
            fromUtc: $fromUtc,
            untilUtc: $untilUtc,
        );

        $parameterMap = $this->resolveParameterMap($reportRun, ParameterCategory::Counter, true);
        $parameterKeys = $this->resolveParameterKeys($reportRun, array_keys($parameterMap));

        $header = [
            'bucket_start',
            'bucket_end',
            'window_name',
            'parameter_key',
            'parameter_label',
            'sample_count',
            'from_value',
            'to_value',
            'consumption',
        ];

        $rows = [];

        foreach ($parameterKeys as $parameterKey) {
            /** @var Collection<int, array{recorded_at: CarbonImmutable, value: float}> $series */
            $series = $logs
                ->map(function (DeviceTelemetryLog $log) use ($parameterKey): ?array {
                    $value = data_get($log->transformed_values, $parameterKey);

                    if (! is_numeric($value)) {
                        return null;
                    }

                    return [
                        'recorded_at' => CarbonImmutable::instance($log->recorded_at),
                        'value' => (float) $value,
                    ];
                })
                ->filter()
                ->values();

            if ($series->isEmpty()) {
                continue;
            }

            $buckets = $this->bucketNumericSeries($series, $windows);

            foreach ($buckets as $bucket) {
                $values = $bucket['values'];
                $sampleCount = count($values);

                if ($sampleCount === 0) {
                    continue;
                }

                $fromValue = $values[0];
                $toValue = $values[$sampleCount - 1];
                $consumption = max(0.0, round($toValue - $fromValue, 6));
                $window = $bucket['window'];

                $rows[] = [
                    $window['start_local']->format('Y-m-d H:i:s'),
                    $window['end_local']->format('Y-m-d H:i:s'),
                    $window['name'],
                    $parameterKey,
                    $parameterMap[$parameterKey] ?? $parameterKey,
                    $sampleCount,
                    $fromValue,
                    $toValue,
                    $consumption,
                ];
            }
        }

        return [$header, $rows];
    }

    /**
     * @param  Collection<int, DeviceTelemetryLog>  $logs
     * @return array{0: array<int, string>, 1: array<int, array<int, mixed>>}
     */
    private function buildStateUtilizationReportRows(
        ReportRun $reportRun,
        Collection $logs,
        string $timezone,
        CarbonImmutable $fromUtc,
        CarbonImmutable $untilUtc,
    ): array {
        $grouping = $this->resolveAggregationGrouping($reportRun);
        $windows = $this->resolveAggregationWindows(
            reportRun: $reportRun,
            grouping: $grouping,
            timezone: $timezone,
            fromUtc: $fromUtc,
            untilUtc: $untilUtc,
        );

        $parameterMap = $this->resolveParameterMap($reportRun, ParameterCategory::State, false);
        $parameterKeys = $this->resolveParameterKeys($reportRun, array_keys($parameterMap));

        $header = [
            'row_type',
            'window_start',
            'window_end',
            'window_name',
            'parameter_key',
            'parameter_label',
            'state',
            'duration_seconds',
            'duration_hms',
            'percentage',
            'changed_at',
            'from_state',
            'to_state',
        ];

        $rows = [];
        $carryForwardStates = $this->resolveCarryForwardStates($reportRun, $parameterKeys, $fromUtc);

        foreach ($parameterKeys as $parameterKey) {
            ['intervals' => $intervals, 'transitions' => $transitions] = $this->buildStateTimeline(
                parameterKey: $parameterKey,
                logs: $logs,
                initialState: $carryForwardStates[$parameterKey] ?? null,
                fromUtc: $fromUtc,
                untilUtc: $untilUtc,
            );

            $durationsByWindow = $this->allocateStateDurationsAcrossWindows($intervals, $windows);

            foreach ($windows as $window) {
                $windowDurations = $durationsByWindow[$window['key']] ?? [];

                if ($windowDurations === []) {
                    continue;
                }

                ksort($windowDurations);
                $totalSeconds = array_sum($windowDurations);

                foreach ($windowDurations as $state => $durationSeconds) {
                    $resolvedDurationSeconds = (int) round($durationSeconds);
                    $percentage = $totalSeconds > 0
                        ? round(($durationSeconds / $totalSeconds) * 100, 2)
                        : 0.0;

                    $rows[] = [
                        'summary',
                        $window['start_local']->format('Y-m-d H:i:s'),
                        $window['end_local']->format('Y-m-d H:i:s'),
                        $window['name'],
                        $parameterKey,
                        $parameterMap[$parameterKey] ?? $parameterKey,
                        $state,
                        $resolvedDurationSeconds,
                        $this->formatDuration($resolvedDurationSeconds),
                        $percentage,
                        null,
                        null,
                        null,
                    ];
                }
            }

            foreach ($transitions as $transition) {
                $rows[] = [
                    'transition',
                    null,
                    null,
                    null,
                    $parameterKey,
                    $parameterMap[$parameterKey] ?? $parameterKey,
                    null,
                    null,
                    null,
                    null,
                    $transition['changed_at']->setTimezone($timezone)->format('Y-m-d H:i:s'),
                    $transition['from_state'],
                    $transition['to_state'],
                ];
            }
        }

        return [$header, $rows];
    }

    private function resolveAggregationGrouping(ReportRun $reportRun): ReportGrouping
    {
        return $reportRun->grouping instanceof ReportGrouping
            ? $reportRun->grouping
            : ReportGrouping::Hourly;
    }

    /**
     * @return array<int, array{
     *     key: string,
     *     start_utc: CarbonImmutable,
     *     end_utc: CarbonImmutable,
     *     start_local: CarbonImmutable,
     *     end_local: CarbonImmutable,
     *     name: string
     * }>
     */
    private function resolveAggregationWindows(
        ReportRun $reportRun,
        ReportGrouping $grouping,
        string $timezone,
        CarbonImmutable $fromUtc,
        CarbonImmutable $untilUtc,
    ): array {
        $fromLocal = $fromUtc->setTimezone($timezone);
        $untilLocal = $untilUtc->setTimezone($timezone);

        if ($untilLocal->lessThanOrEqualTo($fromLocal)) {
            return [];
        }

        return match ($grouping) {
            ReportGrouping::Hourly, ReportGrouping::Daily, ReportGrouping::Monthly => $this->resolveCalendarWindows(
                grouping: $grouping,
                fromLocal: $fromLocal,
                untilLocal: $untilLocal,
            ),
            ReportGrouping::ShiftSchedule => $this->resolveShiftScheduleWindows(
                reportRun: $reportRun,
                fromLocal: $fromLocal,
                untilLocal: $untilLocal,
            ),
        };
    }

    /**
     * @return array<int, array{
     *     key: string,
     *     start_utc: CarbonImmutable,
     *     end_utc: CarbonImmutable,
     *     start_local: CarbonImmutable,
     *     end_local: CarbonImmutable,
     *     name: string
     * }>
     */
    private function resolveCalendarWindows(
        ReportGrouping $grouping,
        CarbonImmutable $fromLocal,
        CarbonImmutable $untilLocal,
    ): array {
        $windows = [];
        $cursor = match ($grouping) {
            ReportGrouping::Hourly => $fromLocal->startOfHour(),
            ReportGrouping::Daily => $fromLocal->startOfDay(),
            ReportGrouping::Monthly => $fromLocal->startOfMonth(),
            ReportGrouping::ShiftSchedule => $fromLocal,
        };

        while ($cursor->lessThan($untilLocal)) {
            $windowEnd = match ($grouping) {
                ReportGrouping::Hourly => $cursor->addHour(),
                ReportGrouping::Daily => $cursor->addDay(),
                ReportGrouping::Monthly => $cursor->addMonth(),
                ReportGrouping::ShiftSchedule => $cursor,
            };

            if ($windowEnd->greaterThan($fromLocal)) {
                $windows[] = $this->buildWindow(
                    startLocal: $cursor,
                    endLocal: $windowEnd,
                    name: match ($grouping) {
                        ReportGrouping::Hourly => $cursor->format('Y-m-d H:i'),
                        ReportGrouping::Daily => $cursor->format('Y-m-d'),
                        ReportGrouping::Monthly => $cursor->format('Y-m'),
                        ReportGrouping::ShiftSchedule => '',
                    },
                );
            }

            $cursor = $windowEnd;
        }

        return $windows;
    }

    /**
     * @return array<int, array{
     *     key: string,
     *     start_utc: CarbonImmutable,
     *     end_utc: CarbonImmutable,
     *     start_local: CarbonImmutable,
     *     end_local: CarbonImmutable,
     *     name: string
     * }>
     */
    private function resolveShiftScheduleWindows(
        ReportRun $reportRun,
        CarbonImmutable $fromLocal,
        CarbonImmutable $untilLocal,
    ): array {
        $shiftSchedule = $this->normalizeShiftSchedule(data_get($reportRun->payload, 'shift_schedule'));

        if ($shiftSchedule === null) {
            throw new RuntimeException('Shift schedule grouping requires a selected shift schedule in report payload.');
        }

        $segments = $this->buildShiftSegments($shiftSchedule['windows']);
        $firstStart = $segments[0]['start_minutes'];

        $cycleStart = $fromLocal->startOfDay()->addMinutes($firstStart);

        if ($fromLocal->lessThan($cycleStart)) {
            $cycleStart = $cycleStart->subDay();
        }

        $windows = [];

        while ($cycleStart->lessThan($untilLocal)) {
            foreach ($segments as $segment) {
                $windowStart = $cycleStart->addMinutes($segment['offset_minutes']);
                $windowEnd = $windowStart->addMinutes($segment['duration_minutes']);

                if ($windowEnd->lessThanOrEqualTo($fromLocal) || $windowStart->greaterThanOrEqualTo($untilLocal)) {
                    continue;
                }

                $windows[] = $this->buildWindow(
                    startLocal: $windowStart,
                    endLocal: $windowEnd,
                    name: "{$shiftSchedule['name']} - {$segment['name']}",
                );
            }

            $cycleStart = $cycleStart->addDay();
        }

        return $windows;
    }

    /**
     * @param  array<int, array{id: string, name: string, start: string, end: string}>  $windows
     * @return array<int, array{
     *     name: string,
     *     start_minutes: int,
     *     end_minutes: int,
     *     offset_minutes: int,
     *     duration_minutes: int
     * }>
     */
    private function buildShiftSegments(array $windows): array
    {
        $segments = [];
        $seenStarts = [];

        foreach ($windows as $window) {
            $startMinutes = $this->timeToMinutes($window['start']);
            $endMinutes = $this->timeToMinutes($window['end']);

            if ($startMinutes === null || $endMinutes === null) {
                throw new RuntimeException('Shift schedule contains invalid time values.');
            }

            if (in_array($startMinutes, $seenStarts, true)) {
                throw new RuntimeException('Shift schedule contains duplicate window start times.');
            }

            $seenStarts[] = $startMinutes;

            $durationMinutes = $endMinutes - $startMinutes;

            if ($durationMinutes <= 0) {
                $durationMinutes += 1440;
            }

            if ($durationMinutes <= 0 || $durationMinutes > 1440) {
                throw new RuntimeException('Shift schedule contains an invalid duration.');
            }

            $segments[] = [
                'name' => $window['name'],
                'start_minutes' => $startMinutes,
                'end_minutes' => $endMinutes,
                'duration_minutes' => $durationMinutes,
            ];
        }

        if ($segments === []) {
            throw new RuntimeException('Shift schedule must define at least one window.');
        }

        $firstStart = $segments[0]['start_minutes'];
        $segmentCount = count($segments);

        foreach ($segments as $index => $segment) {
            $nextSegment = $segments[($index + 1) % $segmentCount];
            $deltaToNextStart = $nextSegment['start_minutes'] - $segment['start_minutes'];

            if ($deltaToNextStart <= 0) {
                $deltaToNextStart += 1440;
            }

            if ($segment['duration_minutes'] > $deltaToNextStart) {
                throw new RuntimeException('Shift schedule windows overlap.');
            }

            $offsetMinutes = $segment['start_minutes'] - $firstStart;

            if ($offsetMinutes < 0) {
                $offsetMinutes += 1440;
            }

            $segments[$index]['offset_minutes'] = $offsetMinutes;
        }

        return $segments;
    }

    private function timeToMinutes(string $time): ?int
    {
        if (preg_match('/^(?<hour>\d{2}):(?<minute>\d{2})$/', $time, $matches) !== 1) {
            return null;
        }

        $hour = (int) $matches['hour'];
        $minute = (int) $matches['minute'];

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return ($hour * 60) + $minute;
    }

    /**
     * @return array{
     *     id: string,
     *     name: string,
     *     windows: array<int, array{id: string, name: string, start: string, end: string}>
     * }|null
     */
    private function normalizeShiftSchedule(mixed $shiftSchedule): ?array
    {
        if (! is_array($shiftSchedule)) {
            return null;
        }

        $scheduleId = $this->stringFromArray($shiftSchedule, 'id');
        $scheduleName = $this->stringFromArray($shiftSchedule, 'name');
        $windows = is_array($shiftSchedule['windows'] ?? null) ? $shiftSchedule['windows'] : [];

        if ($scheduleId === '' || $scheduleName === '' || $windows === []) {
            return null;
        }

        $normalizedWindows = [];

        foreach ($windows as $window) {
            if (! is_array($window)) {
                continue;
            }

            $windowId = $this->stringFromArray($window, 'id');
            $windowName = $this->stringFromArray($window, 'name');
            $start = $this->stringFromArray($window, 'start');
            $end = $this->stringFromArray($window, 'end');

            if (
                $windowId === ''
                || $windowName === ''
                || preg_match('/^\d{2}:\d{2}$/', $start) !== 1
                || preg_match('/^\d{2}:\d{2}$/', $end) !== 1
            ) {
                continue;
            }

            $normalizedWindows[] = [
                'id' => $windowId,
                'name' => $windowName,
                'start' => $start,
                'end' => $end,
            ];
        }

        if ($normalizedWindows === []) {
            return null;
        }

        return [
            'id' => $scheduleId,
            'name' => $scheduleName,
            'windows' => $normalizedWindows,
        ];
    }

    /**
     * @param  Collection<int, array{recorded_at: CarbonImmutable, value: float}>  $series
     * @param  array<int, array{
     *     key: string,
     *     start_utc: CarbonImmutable,
     *     end_utc: CarbonImmutable,
     *     start_local: CarbonImmutable,
     *     end_local: CarbonImmutable,
     *     name: string
     * }>  $windows
     * @return array<string, array{
     *     window: array{
     *         key: string,
     *         start_utc: CarbonImmutable,
     *         end_utc: CarbonImmutable,
     *         start_local: CarbonImmutable,
     *         end_local: CarbonImmutable,
     *         name: string
     *     },
     *     values: array<int, float>
     * }>
     */
    private function bucketNumericSeries(Collection $series, array $windows): array
    {
        if ($windows === []) {
            return [];
        }

        $buckets = [];
        $windowIndex = 0;
        $windowCount = count($windows);

        foreach ($series as $point) {
            while (
                $windowIndex < $windowCount
                && $point['recorded_at']->greaterThanOrEqualTo($windows[$windowIndex]['end_utc'])
            ) {
                $windowIndex++;
            }

            if ($windowIndex >= $windowCount) {
                break;
            }

            $window = $windows[$windowIndex];

            if ($point['recorded_at']->lessThan($window['start_utc'])) {
                continue;
            }

            if (! array_key_exists($window['key'], $buckets)) {
                $buckets[$window['key']] = [
                    'window' => $window,
                    'values' => [],
                ];
            }

            $buckets[$window['key']]['values'][] = $point['value'];
        }

        return $buckets;
    }

    /**
     * @param  Collection<int, DeviceTelemetryLog>  $logs
     * @return array{
     *     intervals: array<int, array{state: string, start: CarbonImmutable, end: CarbonImmutable}>,
     *     transitions: array<int, array{changed_at: CarbonImmutable, from_state: string, to_state: string}>
     * }
     */
    private function buildStateTimeline(
        string $parameterKey,
        Collection $logs,
        ?string $initialState,
        CarbonImmutable $fromUtc,
        CarbonImmutable $untilUtc,
    ): array {
        $currentState = is_string($initialState) && trim($initialState) !== ''
            ? trim($initialState)
            : null;
        $currentStartedAt = $fromUtc;
        $intervals = [];
        $transitions = [];

        foreach ($logs as $log) {
            $stateValue = data_get($log->transformed_values, $parameterKey);

            if ((! is_scalar($stateValue) && ! $stateValue instanceof \Stringable) || trim((string) $stateValue) === '') {
                continue;
            }

            $newState = trim((string) $stateValue);
            $changedAt = CarbonImmutable::instance($log->recorded_at);

            if ($currentState === null) {
                $currentState = $newState;
                $currentStartedAt = $changedAt;

                continue;
            }

            if ($newState === $currentState) {
                continue;
            }

            if ($changedAt->greaterThan($currentStartedAt)) {
                $intervals[] = [
                    'state' => $currentState,
                    'start' => $currentStartedAt,
                    'end' => $changedAt,
                ];
            }

            $transitions[] = [
                'changed_at' => $changedAt,
                'from_state' => $currentState,
                'to_state' => $newState,
            ];

            $currentState = $newState;
            $currentStartedAt = $changedAt;
        }

        if (is_string($currentState) && $currentStartedAt->lessThan($untilUtc)) {
            $intervals[] = [
                'state' => $currentState,
                'start' => $currentStartedAt,
                'end' => $untilUtc,
            ];
        }

        return [
            'intervals' => $intervals,
            'transitions' => $transitions,
        ];
    }

    /**
     * @param  array<int, array{state: string, start: CarbonImmutable, end: CarbonImmutable}>  $intervals
     * @param  array<int, array{
     *     key: string,
     *     start_utc: CarbonImmutable,
     *     end_utc: CarbonImmutable,
     *     start_local: CarbonImmutable,
     *     end_local: CarbonImmutable,
     *     name: string
     * }>  $windows
     * @return array<string, array<string, int>>
     */
    private function allocateStateDurationsAcrossWindows(array $intervals, array $windows): array
    {
        $durationsByWindow = [];

        if ($intervals === [] || $windows === []) {
            return $durationsByWindow;
        }

        $windowIndex = 0;
        $windowCount = count($windows);

        foreach ($intervals as $interval) {
            while ($windowIndex < $windowCount && $windows[$windowIndex]['end_utc']->lessThanOrEqualTo($interval['start'])) {
                $windowIndex++;
            }

            $scanIndex = $windowIndex;

            while ($scanIndex < $windowCount && $windows[$scanIndex]['start_utc']->lessThan($interval['end'])) {
                $window = $windows[$scanIndex];
                $overlapStart = $interval['start']->greaterThan($window['start_utc']) ? $interval['start'] : $window['start_utc'];
                $overlapEnd = $interval['end']->lessThan($window['end_utc']) ? $interval['end'] : $window['end_utc'];

                if ($overlapEnd->greaterThan($overlapStart)) {
                    $seconds = $overlapStart->diffInSeconds($overlapEnd);

                    if ($seconds > 0) {
                        $durationsByWindow[$window['key']][$interval['state']] = (int) (
                            ($durationsByWindow[$window['key']][$interval['state']] ?? 0)
                            + $seconds
                        );
                    }
                }

                if ($window['end_utc']->greaterThanOrEqualTo($interval['end'])) {
                    break;
                }

                $scanIndex++;
            }
        }

        return $durationsByWindow;
    }

    /**
     * @param  array<int, string>  $parameterKeys
     * @return array<string, string>
     */
    private function resolveCarryForwardStates(
        ReportRun $reportRun,
        array $parameterKeys,
        CarbonImmutable $fromUtc,
    ): array {
        if ($parameterKeys === []) {
            return [];
        }

        $resolvedStates = [];
        $unresolvedKeys = array_values($parameterKeys);
        $cursor = $fromUtc;

        while ($unresolvedKeys !== []) {
            $batch = DeviceTelemetryLog::query()
                ->where('device_id', $reportRun->device_id)
                ->where('recorded_at', '<', $cursor)
                ->orderByDesc('recorded_at')
                ->limit(500)
                ->get(['recorded_at', 'transformed_values']);

            if ($batch->isEmpty()) {
                break;
            }

            foreach ($batch as $log) {
                foreach ($unresolvedKeys as $index => $parameterKey) {
                    $value = data_get($log->transformed_values, $parameterKey);

                    if ((! is_scalar($value) && ! $value instanceof \Stringable) || trim((string) $value) === '') {
                        continue;
                    }

                    $resolvedStates[$parameterKey] = trim((string) $value);
                    unset($unresolvedKeys[$index]);
                }
            }

            $cursor = CarbonImmutable::instance($batch->last()->recorded_at);
            $unresolvedKeys = array_values($unresolvedKeys);
        }

        return $resolvedStates;
    }

    /**
     * @return array{
     *     key: string,
     *     start_utc: CarbonImmutable,
     *     end_utc: CarbonImmutable,
     *     start_local: CarbonImmutable,
     *     end_local: CarbonImmutable,
     *     name: string
     * }
     */
    private function buildWindow(
        CarbonImmutable $startLocal,
        CarbonImmutable $endLocal,
        string $name,
    ): array {
        return [
            'key' => $startLocal->toIso8601String(),
            'start_utc' => $startLocal->utc(),
            'end_utc' => $endLocal->utc(),
            'start_local' => $startLocal,
            'end_local' => $endLocal,
            'name' => $name,
        ];
    }

    /**
     * @param  array<int, string>  $availableParameterKeys
     * @return array<int, string>
     */
    private function resolveParameterKeys(ReportRun $reportRun, array $availableParameterKeys): array
    {
        $requestedKeys = is_array($reportRun->parameter_keys)
            ? array_values(array_filter($reportRun->parameter_keys, fn (string $value): bool => trim($value) !== ''))
            : [];

        if ($requestedKeys === []) {
            return $availableParameterKeys;
        }

        return array_values(array_filter(
            array_unique($requestedKeys),
            fn (string $parameterKey): bool => in_array($parameterKey, $availableParameterKeys, true),
        ));
    }

    /**
     * @return array<string, string>
     */
    private function resolveParameterMap(
        ReportRun $reportRun,
        ?ParameterCategory $category,
        bool $numericOnly,
    ): array {
        $profileVersionId = $reportRun->device?->device_profile_version_id;

        if (! is_numeric($profileVersionId)) {
            return [];
        }

        $query = ProfileParameterDefinition::query()
            ->with('channel:id,key,direction,device_profile_version_id')
            ->where('is_active', true)
            ->whereHas('channel', function (Builder $channelQuery) use ($profileVersionId): void {
                $channelQuery
                    ->where('device_profile_version_id', (int) $profileVersionId)
                    ->where('direction', 'publish');
            })
            ->orderBy('sequence');

        if ($numericOnly) {
            $query->whereIn('type', [ParameterDataType::Integer->value, ParameterDataType::Decimal->value]);
        }

        if ($category instanceof ParameterCategory) {
            $query->where(function (Builder $builder) use ($category): void {
                $builder
                    ->where('category', $category->value)
                    ->orWhere('validation_rules->category', $category->value);
            });
        }

        $map = [];

        foreach ($query->get(['key', 'label', 'device_channel_id']) as $parameterDefinition) {
            if (trim($parameterDefinition->key) === '') {
                continue;
            }

            if (array_key_exists($parameterDefinition->key, $map)) {
                continue;
            }

            $map[$parameterDefinition->key] = trim($parameterDefinition->label) !== ''
                ? $parameterDefinition->label
                : $parameterDefinition->key;
        }

        if ($map === [] && $category instanceof ParameterCategory) {
            return $this->resolveParameterMap($reportRun, null, $numericOnly);
        }

        return $map;
    }

    /**
     * @param  array<int, string>  $header
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function buildCsvContent(array $header, array $rows): string
    {
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            throw new RuntimeException('Unable to open temporary stream for CSV generation.');
        }

        fputcsv($stream, $header);

        foreach ($rows as $row) {
            $normalizedRow = array_map(
                fn (mixed $value): float|int|string|null => $this->normalizeCsvValue($value),
                $row,
            );

            /** @var array<int|string, bool|float|int|string|null> $normalizedRow */
            fputcsv($stream, $normalizedRow);
        }

        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        if (! is_string($content)) {
            throw new RuntimeException('Unable to read generated CSV content.');
        }

        return $content;
    }

    private function normalizeCsvValue(mixed $value): float|int|string|null
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);

            return is_string($encoded) ? $encoded : null;
        }

        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->toIso8601String();
        }

        if (is_object($value) && ! $value instanceof \Stringable) {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);

            return is_string($encoded) ? $encoded : null;
        }

        if (is_int($value) || is_float($value) || is_string($value)) {
            return $value;
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return null;
    }

    /**
     * @param  array<mixed, mixed>  $source
     */
    private function stringFromArray(array $source, string $key): string
    {
        $value = $source[$key] ?? null;

        if (! is_scalar($value) && ! $value instanceof \Stringable) {
            return '';
        }

        return trim((string) $value);
    }

    private function stringConfig(string $key, string $default): string
    {
        $value = config($key, $default);

        return is_string($value) ? $value : $default;
    }

    private function formatDuration(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $remainingSeconds);
    }
}
