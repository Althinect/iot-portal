<?php

declare(strict_types=1);

namespace App\Domain\IoTDashboard\Widgets\StatusSummary;

use App\Domain\IoTDashboard\Contracts\WidgetConfig;
use App\Domain\IoTDashboard\Enums\WidgetType;
use App\Domain\IoTDashboard\Widgets\Concerns\NormalizesWidgetConfig;

final class StatusSummaryConfig implements WidgetConfig
{
    use NormalizesWidgetConfig;

    /**
     * @param  array<int, array{tiles: array<int, array{
     *     key: string,
     *     label: string,
     *     unit: string|null,
     *     base_color: string,
     *     threshold_ranges: array<int, array{from: int|float|null, to: int|float|null, color: string}>,
     *     source: array<string, mixed>
     * }>} >  $rows
     */
    private function __construct(
        private readonly array $rows,
        private readonly bool $useWebsocket,
        private readonly bool $usePolling,
        private readonly int $pollingIntervalSeconds,
        private readonly int $lookbackMinutes,
        private readonly int $maxPoints,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): static
    {
        $transport = is_array($config['transport'] ?? null)
            ? $config['transport']
            : [];
        $window = is_array($config['window'] ?? null)
            ? $config['window']
            : [];

        return new self(
            rows: self::normalizeRows($config['rows'] ?? []),
            useWebsocket: self::normalizeBool(
                $transport['use_websocket'] ?? $config['use_websocket'] ?? true,
                true,
            ),
            usePolling: self::normalizeBool(
                $transport['use_polling'] ?? $config['use_polling'] ?? true,
                true,
            ),
            pollingIntervalSeconds: self::normalizeInt(
                $transport['polling_interval_seconds'] ?? $config['polling_interval_seconds'] ?? 10,
                10,
                2,
                300,
            ),
            lookbackMinutes: self::normalizeInt(
                $window['lookback_minutes'] ?? $config['lookback_minutes'] ?? 180,
                180,
                1,
                129600,
            ),
            maxPoints: self::normalizeInt(
                $window['max_points'] ?? $config['max_points'] ?? 1,
                1,
                1,
                25,
            ),
        );
    }

    public function type(): WidgetType
    {
        return WidgetType::StatusSummary;
    }

    public function series(): array
    {
        return array_map(
            static fn (array $tile): array => [
                'key' => $tile['key'],
                'label' => $tile['label'],
                'color' => $tile['base_color'],
                'unit' => $tile['unit'],
                'source' => $tile['source'],
            ],
            $this->tiles(),
        );
    }

    /**
     * @return array<int, array{tiles: array<int, array{
     *     key: string,
     *     label: string,
     *     unit: string|null,
     *     base_color: string,
     *     threshold_ranges: array<int, array{from: int|float|null, to: int|float|null, color: string}>,
     *     source: array<string, mixed>
     * }>} >
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * @return array<int, array{
     *     key: string,
     *     label: string,
     *     unit: string|null,
     *     base_color: string,
     *     threshold_ranges: array<int, array{from: int|float|null, to: int|float|null, color: string}>,
     *     source: array<string, mixed>
     * }>
     */
    public function tiles(): array
    {
        return collect($this->rows)
            ->flatMap(static fn (array $row): array => $row['tiles'])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{tile_keys: array<int, string>}>
     */
    public function layoutRows(): array
    {
        return array_map(
            static fn (array $row): array => [
                'tile_keys' => array_map(
                    static fn (array $tile): string => $tile['key'],
                    $row['tiles'],
                ),
            ],
            $this->rows,
        );
    }

    public function useWebsocket(): bool
    {
        return $this->useWebsocket;
    }

    public function usePolling(): bool
    {
        return $this->usePolling;
    }

    public function pollingIntervalSeconds(): int
    {
        return $this->pollingIntervalSeconds;
    }

    public function lookbackMinutes(): int
    {
        return $this->lookbackMinutes;
    }

    public function maxPoints(): int
    {
        return $this->maxPoints;
    }

    public function supportsRealtime(): bool
    {
        return true;
    }

    public function toArray(): array
    {
        return [
            'rows' => $this->rows,
            'transport' => [
                'use_websocket' => $this->useWebsocket,
                'use_polling' => $this->usePolling,
                'polling_interval_seconds' => $this->pollingIntervalSeconds,
            ],
            'window' => [
                'lookback_minutes' => $this->lookbackMinutes,
                'max_points' => $this->maxPoints,
            ],
        ];
    }

    public function meta(): array
    {
        return [
            'layout_rows' => $this->layoutRows(),
        ];
    }

    /**
     * @param  array<int, mixed>|mixed  $rows
     * @return array<int, array{tiles: array<int, array{
     *     key: string,
     *     label: string,
     *     unit: string|null,
     *     base_color: string,
     *     threshold_ranges: array<int, array{from: int|float|null, to: int|float|null, color: string}>,
     *     source: array<string, mixed>
     * }>} >
     */
    private static function normalizeRows(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $normalizedRows = [];
        $seenKeys = [];

        foreach (array_values($rows) as $rowIndex => $row) {
            if (! is_array($row)) {
                continue;
            }

            $normalizedTiles = [];

            foreach (array_values(is_array($row['tiles'] ?? null) ? $row['tiles'] : []) as $tileIndex => $tile) {
                $normalizedTile = self::normalizeTile($tile, $rowIndex, $tileIndex);

                if ($normalizedTile === null || in_array($normalizedTile['key'], $seenKeys, true)) {
                    continue;
                }

                $seenKeys[] = $normalizedTile['key'];
                $normalizedTiles[] = $normalizedTile;
            }

            if ($normalizedTiles === []) {
                continue;
            }

            $normalizedRows[] = [
                'tiles' => $normalizedTiles,
            ];
        }

        return $normalizedRows;
    }

    /**
     * @param  array<string, mixed>|mixed  $tile
     * @return array{
     *     key: string,
     *     label: string,
     *     unit: string|null,
     *     base_color: string,
     *     threshold_ranges: array<int, array{from: int|float|null, to: int|float|null, color: string}>,
     *     source: array<string, mixed>
     * }|null
     */
    private static function normalizeTile(mixed $tile, int $rowIndex, int $tileIndex): ?array
    {
        if (! is_array($tile)) {
            return null;
        }

        $source = self::normalizeSource($tile['source'] ?? []);
        $key = self::normalizeTileKey($tile['key'] ?? null, $source, $rowIndex, $tileIndex);

        if ($key === '') {
            return null;
        }

        return [
            'key' => $key,
            'label' => is_string($tile['label'] ?? null) && trim((string) $tile['label']) !== ''
                ? trim((string) $tile['label'])
                : $key,
            'unit' => is_string($tile['unit'] ?? null) && trim((string) $tile['unit']) !== ''
                ? trim((string) $tile['unit'])
                : null,
            'base_color' => self::defaultBaseColor(),
            'threshold_ranges' => self::normalizeThresholdRanges($tile['threshold_ranges'] ?? []),
            'source' => $source,
        ];
    }

    /**
     * @param  array<string, mixed>|mixed  $source
     * @return array<string, mixed>
     */
    private static function normalizeSource(mixed $source): array
    {
        $parameterKey = is_array($source) && is_string($source['parameter_key'] ?? null)
            ? trim((string) $source['parameter_key'])
            : '';

        return [
            'type' => StatusSummaryMetricSourceType::LatestParameter->value,
            'parameter_key' => $parameterKey,
        ];
    }

    /**
     * @param  array<int, mixed>|mixed  $ranges
     * @return array<int, array{from: int|float|null, to: int|float|null, color: string}>
     */
    private static function normalizeThresholdRanges(mixed $ranges): array
    {
        if (! is_array($ranges)) {
            return [];
        }

        $normalizedRanges = [];

        foreach (array_values($ranges) as $index => $range) {
            if (! is_array($range)) {
                continue;
            }

            $color = is_string($range['color'] ?? null) && trim((string) $range['color']) !== ''
                ? trim((string) $range['color'])
                : self::seriesPalette()[$index % count(self::seriesPalette())];

            $normalizedRanges[] = [
                'from' => self::normalizeNullableNumber($range['from'] ?? null),
                'to' => self::normalizeNullableNumber($range['to'] ?? null),
                'color' => $color,
            ];
        }

        return $normalizedRanges;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private static function normalizeTileKey(mixed $value, array $source, int $rowIndex, int $tileIndex): string
    {
        $sourceKey = self::sourceTileKey($source);

        if ($sourceKey !== '') {
            return $sourceKey;
        }

        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return 'tile_'.$rowIndex.'_'.$tileIndex;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private static function sourceTileKey(array $source): string
    {
        return is_string($source['parameter_key'] ?? null)
            ? trim((string) $source['parameter_key'])
            : '';
    }

    private static function normalizeNullableNumber(mixed $value): int|float|null
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && trim($value) === '') {
            return null;
        }

        return is_numeric($value) ? $value + 0 : null;
    }

    private static function defaultBaseColor(): string
    {
        return '#000000';
    }
}
