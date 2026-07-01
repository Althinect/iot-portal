<?php

declare(strict_types=1);

namespace App\Domain\IoTDashboard\Widgets\SteamMeter;

use App\Domain\IoTDashboard\Contracts\WidgetConfig;
use App\Domain\IoTDashboard\Enums\WidgetType;

final class SteamMeterConfig implements WidgetConfig
{
    /**
     * @param  array{flow: array{device_id: int, device_channel_id: int, parameter_key: string}|null, total: array{device_id: int, device_channel_id: int, parameter_key: string}|null}  $sources
     * @param  array<int, array{label: string, start_time: string, end_time: string}>  $shifts
     */
    public function __construct(
        private readonly array $sources,
        private readonly array $shifts,
        private readonly bool $usePolling,
        private readonly int $pollingIntervalSeconds,
        private readonly int $lookbackMinutes,
        private readonly int $maxPoints,
    ) {}

    public static function fromArray(array $config): static
    {
        return new self(
            sources: self::normalizeSources($config['sources'] ?? []),
            shifts: self::normalizeShifts($config['shifts'] ?? []),
            usePolling: (bool) data_get($config, 'transport.use_polling', true),
            pollingIntervalSeconds: self::toInt(data_get($config, 'transport.polling_interval_seconds'), 30),
            lookbackMinutes: self::toInt(data_get($config, 'window.lookback_minutes'), 1440),
            maxPoints: self::toInt(data_get($config, 'window.max_points'), 1),
        );
    }

    public function type(): WidgetType
    {
        return WidgetType::SteamMeter;
    }

    public function series(): array
    {
        return [];
    }

    public function useWebsocket(): bool
    {
        return false;
    }

    public function usePolling(): bool
    {
        return $this->usePolling;
    }

    public function pollingIntervalSeconds(): int
    {
        return max(10, $this->pollingIntervalSeconds);
    }

    public function lookbackMinutes(): int
    {
        return max(60, $this->lookbackMinutes);
    }

    public function maxPoints(): int
    {
        return max(1, $this->maxPoints);
    }

    /**
     * @return array{flow: array{device_id: int, device_channel_id: int, parameter_key: string}|null, total: array{device_id: int, device_channel_id: int, parameter_key: string}|null}
     */
    public function sources(): array
    {
        return $this->sources;
    }

    /**
     * @return array<int, array{label: string, start_time: string, end_time: string}>
     */
    public function shifts(): array
    {
        return $this->shifts;
    }

    public function toArray(): array
    {
        return [
            'sources' => $this->sources,
            'shifts' => $this->shifts,
            'transport' => [
                'use_websocket' => false,
                'use_polling' => $this->usePolling(),
                'polling_interval_seconds' => $this->pollingIntervalSeconds(),
            ],
            'window' => [
                'lookback_minutes' => $this->lookbackMinutes(),
                'max_points' => $this->maxPoints(),
            ],
        ];
    }

    public function meta(): array
    {
        return [
            'sources' => $this->sources,
            'shifts' => $this->shifts,
        ];
    }

    /**
     * @return array<int, array{label: string, start_time: string, end_time: string}>
     */
    public static function defaultShifts(): array
    {
        return [
            ['label' => 'Shift 1', 'start_time' => '00:30', 'end_time' => '08:30'],
            ['label' => 'Shift 2', 'start_time' => '08:30', 'end_time' => '16:30'],
            ['label' => 'Shift 3', 'start_time' => '16:30', 'end_time' => '00:30'],
        ];
    }

    /**
     * @return array{flow: array{device_id: int, device_channel_id: int, parameter_key: string}|null, total: array{device_id: int, device_channel_id: int, parameter_key: string}|null}
     */
    private static function normalizeSources(mixed $sources): array
    {
        $sources = is_array($sources) ? $sources : [];

        return [
            'flow' => self::normalizeSource($sources['flow'] ?? null, 'flow'),
            'total' => self::normalizeSource($sources['total'] ?? null, 'totalisedCount'),
        ];
    }

    /**
     * @return array{device_id: int, device_channel_id: int, parameter_key: string}|null
     */
    private static function normalizeSource(mixed $source, string $defaultParameterKey): ?array
    {
        if (! is_array($source)) {
            return null;
        }

        $deviceId = self::toInt($source['device_id'] ?? null, 0);
        $topicId = self::toInt($source['device_channel_id'] ?? null, 0);
        $parameterKey = is_string($source['parameter_key'] ?? null) && trim($source['parameter_key']) !== ''
            ? trim($source['parameter_key'])
            : $defaultParameterKey;

        if ($deviceId < 1 || $topicId < 1) {
            return null;
        }

        return [
            'device_id' => $deviceId,
            'device_channel_id' => $topicId,
            'parameter_key' => $parameterKey,
        ];
    }

    /**
     * @return array<int, array{label: string, start_time: string, end_time: string}>
     */
    private static function normalizeShifts(mixed $shifts): array
    {
        $shifts = is_array($shifts) ? $shifts : [];
        $normalized = [];

        foreach (array_values($shifts) as $index => $shift) {
            if (! is_array($shift)) {
                continue;
            }

            $startTime = self::normalizeTime($shift['start_time'] ?? null);
            $endTime = self::normalizeTime($shift['end_time'] ?? null);

            if ($startTime === null || $endTime === null || $startTime === $endTime) {
                continue;
            }

            $label = is_string($shift['label'] ?? null) && trim($shift['label']) !== ''
                ? trim($shift['label'])
                : 'Shift '.($index + 1);

            $normalized[] = [
                'label' => $label,
                'start_time' => $startTime,
                'end_time' => $endTime,
            ];
        }

        return $normalized !== [] ? $normalized : self::defaultShifts();
    }

    private static function normalizeTime(mixed $time): ?string
    {
        if (! is_string($time) || preg_match('/^\d{2}:\d{2}$/', trim($time)) !== 1) {
            return null;
        }

        [$hour, $minute] = array_map('intval', explode(':', trim($time)));

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    private static function toInt(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) round((float) $value) : $default;
    }
}
