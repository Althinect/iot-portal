<?php

declare(strict_types=1);

namespace App\Domain\Telemetry\Services;

use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TelemetryQueryService
{
    /**
     * @param  array<int, array{device_id: int, topic_id: int}>|Collection<int, array{device_id: int, topic_id: int}>  $pairs
     * @return Collection<string, DeviceTelemetryLog>
     */
    public function latestLogsForPairs(array|Collection $pairs, ?int $lookbackMinutes = null): Collection
    {
        $normalizedPairs = collect($pairs)
            ->map(fn (array $pair): array => [
                'device_id' => (int) ($pair['device_id'] ?? 0),
                'topic_id' => (int) ($pair['topic_id'] ?? 0),
                'channel_ids' => $this->channelIdsForInput((int) ($pair['topic_id'] ?? 0)),
            ])
            ->filter(fn (array $pair): bool => $pair['device_id'] > 0 && $pair['channel_ids'] !== [])
            ->unique(fn (array $pair): string => $this->pairKey($pair['device_id'], $pair['topic_id']))
            ->values();

        if ($normalizedPairs->isEmpty()) {
            return collect();
        }

        $query = DeviceTelemetryLog::query();

        if (DB::getDriverName() === 'pgsql') {
            $query->selectRaw(
                'DISTINCT ON (device_id, device_channel_id) id, device_id, device_channel_id, recorded_at, transformed_values',
            );
        } else {
            $query->select(['id', 'device_id', 'device_channel_id', 'recorded_at', 'transformed_values']);
        }

        $query->where(function (Builder $query) use ($normalizedPairs): void {
            foreach ($normalizedPairs as $pair) {
                $query->orWhere(function (Builder $query) use ($pair): void {
                    $query
                        ->where('device_id', $pair['device_id'])
                        ->whereIn('device_channel_id', $pair['channel_ids']);
                });
            }
        });

        if ($lookbackMinutes !== null) {
            $query->where('recorded_at', '>=', now()->subMinutes(max(1, $lookbackMinutes)));
        }

        if (DB::getDriverName() === 'pgsql') {
            return $query
                ->orderBy('device_id')
                ->orderBy('device_channel_id')
                ->orderByDesc('recorded_at')
                ->orderByDesc('id')
                ->get()
                ->keyBy(fn (DeviceTelemetryLog $log): string => $this->pairKey(
                    (int) $log->device_id,
                    (int) $log->device_channel_id,
                ));
        }

        return $query
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy(fn (DeviceTelemetryLog $log): string => $this->pairKey(
                (int) $log->device_id,
                (int) $log->device_channel_id,
            ))
            ->map(function (Collection $logs): DeviceTelemetryLog {
                $latestLog = $logs->first();

                if (! $latestLog instanceof DeviceTelemetryLog) {
                    throw new \RuntimeException('Latest telemetry log group was empty.');
                }

                return $latestLog;
            });
    }

    public function latestLog(int $deviceId, int $deviceChannelId, ?int $lookbackMinutes = null): ?DeviceTelemetryLog
    {
        $channelIds = $this->channelIdsForInput($deviceChannelId);

        if ($deviceId < 1 || $channelIds === []) {
            return null;
        }

        $query = DeviceTelemetryLog::query()
            ->where('device_id', $deviceId)
            ->whereIn('device_channel_id', $channelIds);

        if ($lookbackMinutes !== null) {
            $query->where('recorded_at', '>=', now()->subMinutes(max(1, $lookbackMinutes)));
        }

        return $query
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->first(['id', 'device_id', 'device_channel_id', 'recorded_at', 'transformed_values']);
    }

    /**
     * @return array<int, array{timestamp: string, value: int|float, raw_value?: mixed}>
     */
    public function numericSeries(
        int $deviceId,
        int $deviceChannelId,
        string $parameterKey,
        CarbonInterface $fromAt,
        CarbonInterface $untilAt,
        int $maxPoints,
    ): array {
        if ($deviceId < 1 || $this->channelIdsForInput($deviceChannelId) === [] || trim($parameterKey) === '' || $maxPoints < 1) {
            return [];
        }

        if ($this->canUsePostgresJsonExtraction($parameterKey)) {
            return $this->numericSeriesUsingPostgres(
                deviceId: $deviceId,
                deviceChannelId: $deviceChannelId,
                parameterKey: $parameterKey,
                fromAt: $fromAt,
                untilAt: $untilAt,
                maxPoints: $maxPoints,
            );
        }

        return $this->numericSeriesUsingModels(
            deviceId: $deviceId,
            deviceChannelId: $deviceChannelId,
            parameterKey: $parameterKey,
            fromAt: $fromAt,
            untilAt: $untilAt,
            maxPoints: $maxPoints,
        );
    }

    public function counterDelta(
        int $deviceId,
        int $deviceChannelId,
        string $parameterKey,
        CarbonInterface $startAt,
        CarbonInterface $endAt,
        int $precision = 1,
    ): ?float {
        if ($deviceId < 1 || $this->channelIdsForInput($deviceChannelId) === [] || trim($parameterKey) === '' || $endAt->lessThanOrEqualTo($startAt)) {
            return null;
        }

        $endLog = $this->latestLogBefore($deviceId, $deviceChannelId, $endAt);
        $endValue = $this->numericValue($endLog?->transformed_values, $parameterKey);

        if ($endValue === null) {
            return null;
        }

        $baselineLog = $this->latestLogBefore($deviceId, $deviceChannelId, $startAt)
            ?? $this->firstLogBetween($deviceId, $deviceChannelId, $startAt, $endAt);
        $baselineValue = $this->numericValue($baselineLog?->transformed_values, $parameterKey);

        if ($baselineValue === null) {
            return null;
        }

        return round(max(0, $endValue - $baselineValue), $precision);
    }

    public function pairKey(int $deviceId, int $deviceChannelId): string
    {
        return $deviceId.':'.$deviceChannelId;
    }

    private function latestLogBefore(int $deviceId, int $deviceChannelId, CarbonInterface $at): ?DeviceTelemetryLog
    {
        return DeviceTelemetryLog::query()
            ->where('device_id', $deviceId)
            ->whereIn('device_channel_id', $this->channelIdsForInput($deviceChannelId))
            ->where('recorded_at', '<=', $at)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->first(['id', 'recorded_at', 'transformed_values']);
    }

    private function firstLogBetween(
        int $deviceId,
        int $deviceChannelId,
        CarbonInterface $startAt,
        CarbonInterface $endAt,
    ): ?DeviceTelemetryLog {
        return DeviceTelemetryLog::query()
            ->where('device_id', $deviceId)
            ->whereIn('device_channel_id', $this->channelIdsForInput($deviceChannelId))
            ->where('recorded_at', '>=', $startAt)
            ->where('recorded_at', '<=', $endAt)
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->first(['id', 'recorded_at', 'transformed_values']);
    }

    /**
     * @return array<int, array{timestamp: string, value: int|float, raw_value?: mixed}>
     */
    private function numericSeriesUsingPostgres(
        int $deviceId,
        int $deviceChannelId,
        string $parameterKey,
        CarbonInterface $fromAt,
        CarbonInterface $untilAt,
        int $maxPoints,
    ): array {
        $rawValueExpression = "transformed_values ->> '".$this->postgresTextLiteral($parameterKey)."'";
        $numericPattern = '^[-+]?[0-9]*\.?[0-9]+([eE][-+]?[0-9]+)?$';

        return DeviceTelemetryLog::query()
            ->where('device_id', $deviceId)
            ->whereIn('device_channel_id', $this->channelIdsForInput($deviceChannelId))
            ->where('recorded_at', '>=', $fromAt)
            ->where('recorded_at', '<=', $untilAt)
            ->whereRaw("{$rawValueExpression} ~ ?", [$numericPattern])
            ->select(['id', 'recorded_at'])
            ->selectRaw("{$rawValueExpression} AS raw_value")
            ->selectRaw("({$rawValueExpression})::double precision AS numeric_value")
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->limit($maxPoints)
            ->get()
            ->sortBy('recorded_at')
            ->values()
            ->map(function (DeviceTelemetryLog $log): ?array {
                $timestamp = $log->recorded_at?->toIso8601String();
                $rawValue = $log->getAttribute('raw_value');
                $value = $this->coerceNumericValue($rawValue)
                    ?? $this->coerceNumericValue($log->getAttribute('numeric_value'));

                if (! is_string($timestamp) || $value === null) {
                    return null;
                }

                return [
                    'timestamp' => $timestamp,
                    'value' => $value,
                    'raw_value' => $rawValue,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{timestamp: string, value: int|float, raw_value?: mixed}>
     */
    private function numericSeriesUsingModels(
        int $deviceId,
        int $deviceChannelId,
        string $parameterKey,
        CarbonInterface $fromAt,
        CarbonInterface $untilAt,
        int $maxPoints,
    ): array {
        return DeviceTelemetryLog::query()
            ->where('device_id', $deviceId)
            ->whereIn('device_channel_id', $this->channelIdsForInput($deviceChannelId))
            ->where('recorded_at', '>=', $fromAt)
            ->where('recorded_at', '<=', $untilAt)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->limit($maxPoints)
            ->get(['id', 'recorded_at', 'transformed_values'])
            ->sortBy('recorded_at')
            ->values()
            ->map(function (DeviceTelemetryLog $log) use ($parameterKey): ?array {
                $timestamp = $log->recorded_at?->toIso8601String();
                $value = $this->numericValue($log->transformed_values, $parameterKey);

                if (! is_string($timestamp) || $value === null) {
                    return null;
                }

                return [
                    'timestamp' => $timestamp,
                    'value' => $value,
                    'raw_value' => data_get($log->transformed_values, $parameterKey),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function numericValue(mixed $values, string $parameterKey): int|float|null
    {
        $value = is_array($values) ? data_get($values, $parameterKey) : null;

        return $this->coerceNumericValue($value);
    }

    private function coerceNumericValue(mixed $value): int|float|null
    {
        return is_numeric($value) ? $value + 0 : null;
    }

    private function canUsePostgresJsonExtraction(string $parameterKey): bool
    {
        return DB::getDriverName() === 'pgsql'
            && preg_match('/^[A-Za-z0-9_:-]+$/', $parameterKey) === 1;
    }

    private function postgresTextLiteral(string $value): string
    {
        return str_replace("'", "''", $value);
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
}
