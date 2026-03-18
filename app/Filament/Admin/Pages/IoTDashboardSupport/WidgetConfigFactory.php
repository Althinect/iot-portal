<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\IoTDashboardSupport;

use App\Domain\IoTDashboard\Contracts\WidgetConfig;
use App\Domain\IoTDashboard\Enums\WidgetType;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use App\Domain\IoTDashboard\Widgets\BarChart\BarChartConfig;
use App\Domain\IoTDashboard\Widgets\BarChart\BarInterval;
use App\Domain\IoTDashboard\Widgets\GaugeChart\GaugeChartConfig;
use App\Domain\IoTDashboard\Widgets\GaugeChart\GaugeStyle;
use App\Domain\IoTDashboard\Widgets\LineChart\LineChartConfig;
use App\Domain\IoTDashboard\Widgets\StateCard\StateCardConfig;
use App\Domain\IoTDashboard\Widgets\StateCard\StateCardStyle;
use App\Domain\IoTDashboard\Widgets\StateTimeline\StateTimelineConfig;
use App\Domain\IoTDashboard\Widgets\StatusSummary\StatusSummaryConfig;
use BackedEnum;
use Illuminate\Support\Str;

class WidgetConfigFactory
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $resolvedInput
     */
    public function create(WidgetType $type, array $data, array $resolvedInput): WidgetConfig
    {
        return $this->make($type, $data, $resolvedInput, null);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $resolvedInput
     */
    public function update(WidgetType $type, array $data, array $resolvedInput, WidgetConfig $currentConfig): WidgetConfig
    {
        return $this->make($type, $data, $resolvedInput, $currentConfig);
    }

    /**
     * @return array<string, mixed>
     */
    public function editFormData(IoTDashboardWidget $widget): array
    {
        $type = $widget->widgetType();
        $layout = $widget->layoutArray();
        $config = $widget->configObject();

        $data = [
            'widget_type' => $type->value,
            'title' => $widget->title,
            'device_id' => (string) $widget->device_id,
            'schema_version_topic_id' => (string) $widget->schema_version_topic_id,
            'parameter_keys' => collect($config->series())->pluck('key')->values()->all(),
            'parameter_key' => collect($config->series())->pluck('key')->first(),
            'use_websocket' => $config->useWebsocket(),
            'use_polling' => $config->usePolling(),
            'polling_interval_seconds' => $config->pollingIntervalSeconds(),
            'lookback_minutes' => $config->lookbackMinutes(),
            'max_points' => $config->maxPoints(),
            'grid_columns' => (string) $layout['w'],
            'card_height_px' => $layout['card_height_px'],
        ];

        if ($config instanceof BarChartConfig) {
            $data['bar_interval'] = $config->barInterval()->value;
        }

        if ($config instanceof GaugeChartConfig) {
            $data['gauge_style'] = $config->gaugeStyle()->value;
            $data['gauge_min'] = $config->gaugeMinimum();
            $data['gauge_max'] = $config->gaugeMaximum();
            $data['gauge_ranges'] = $config->gaugeRanges();
        }

        if ($config instanceof StateCardConfig) {
            $data['display_style'] = $config->displayStyle()->value;
            $data['state_mappings'] = $config->stateMappings();
        }

        if ($config instanceof StatusSummaryConfig) {
            $data['rows'] = $this->rowsForEditForm($config->rows());
        }

        if ($config instanceof StateTimelineConfig) {
            $data['state_mappings'] = $config->stateMappings();
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $resolvedInput
     */
    private function make(
        WidgetType $type,
        array $data,
        array $resolvedInput,
        ?WidgetConfig $currentConfig,
    ): WidgetConfig {
        $current = $currentConfig?->toArray() ?? [];
        $transport = is_array($current['transport'] ?? null) ? $current['transport'] : [];
        $window = is_array($current['window'] ?? null) ? $current['window'] : [];
        /** @var array<int, array{key: string, label: string, color: string, unit?: string|null}> $series */
        $series = is_array($resolvedInput['series'] ?? null) ? $resolvedInput['series'] : [];
        $parameterMetadata = is_array($resolvedInput['parameter_metadata'] ?? null)
            ? $resolvedInput['parameter_metadata']
            : [];
        /** @var array<string, array{
         *     label: string,
         *     compact_label: string,
         *     option_label: string,
         *     unit: string|null,
         *     default_color: string,
         *     is_counter: bool
         * }> $parameterMetadata
         */
        $base = [
            'series' => $series,
            'transport' => [
                'use_websocket' => (bool) ($data['use_websocket'] ?? $transport['use_websocket'] ?? $this->defaultWebsocket($type)),
                'use_polling' => (bool) ($data['use_polling'] ?? $transport['use_polling'] ?? true),
                'polling_interval_seconds' => $this->toInt(
                    $data['polling_interval_seconds'] ?? $transport['polling_interval_seconds'] ?? null,
                    $this->defaultPollingInterval($type),
                ),
            ],
            'window' => [
                'lookback_minutes' => $this->toInt(
                    $data['lookback_minutes'] ?? $window['lookback_minutes'] ?? null,
                    $this->defaultLookback($type),
                ),
                'max_points' => $this->toInt(
                    $data['max_points'] ?? $window['max_points'] ?? null,
                    $this->defaultMaxPoints($type),
                ),
            ],
        ];

        return match ($type) {
            WidgetType::LineChart => LineChartConfig::fromArray($base),
            WidgetType::BarChart => BarChartConfig::fromArray([
                ...$base,
                'bar_interval' => $this->normalizeEnumValue(
                    $data['bar_interval'] ?? $current['bar_interval'] ?? BarInterval::Hourly,
                    BarInterval::Hourly->value,
                ),
            ]),
            WidgetType::GaugeChart => GaugeChartConfig::fromArray([
                ...$base,
                'gauge_style' => $this->normalizeEnumValue(
                    $data['gauge_style'] ?? $current['gauge_style'] ?? GaugeStyle::Classic,
                    GaugeStyle::Classic->value,
                ),
                'gauge_min' => $data['gauge_min'] ?? $current['gauge_min'] ?? 0,
                'gauge_max' => $data['gauge_max'] ?? $current['gauge_max'] ?? 100,
                'gauge_ranges' => $data['gauge_ranges'] ?? $current['gauge_ranges'] ?? [],
            ]),
            WidgetType::StatusSummary => StatusSummaryConfig::fromArray([
                ...$base,
                'rows' => $this->normalizeStatusSummaryRows(
                    rows: $data['rows'] ?? $current['rows'] ?? [],
                    parameterMetadata: $parameterMetadata,
                ),
            ]),
            WidgetType::StateCard => StateCardConfig::fromArray([
                ...$base,
                'display_style' => $this->normalizeEnumValue(
                    $data['display_style'] ?? $current['display_style'] ?? StateCardStyle::Toggle,
                    StateCardStyle::Toggle->value,
                ),
                'state_mappings' => $data['state_mappings'] ?? $current['state_mappings'] ?? [],
            ]),
            WidgetType::StateTimeline => StateTimelineConfig::fromArray([
                ...$base,
                'state_mappings' => $data['state_mappings'] ?? $current['state_mappings'] ?? [],
            ]),
        };
    }

    private function defaultWebsocket(WidgetType $type): bool
    {
        return $type !== WidgetType::BarChart;
    }

    private function defaultPollingInterval(WidgetType $type): int
    {
        return $type === WidgetType::BarChart ? 60 : 10;
    }

    private function defaultLookback(WidgetType $type): int
    {
        return match ($type) {
            WidgetType::LineChart => 120,
            WidgetType::BarChart => 43200,
            WidgetType::GaugeChart => 180,
            WidgetType::StatusSummary => 180,
            WidgetType::StateCard => 1440,
            WidgetType::StateTimeline => 360,
        };
    }

    private function defaultMaxPoints(WidgetType $type): int
    {
        return match ($type) {
            WidgetType::LineChart => 240,
            WidgetType::BarChart => 31,
            WidgetType::GaugeChart => 1,
            WidgetType::StatusSummary => 1,
            WidgetType::StateCard => 1,
            WidgetType::StateTimeline => 240,
        };
    }

    private function normalizeEnumValue(mixed $value, string $default): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        return $default;
    }

    private function toInt(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) round((float) $value) : $default;
    }

    private function stringFromMixed(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * @param  array<int, array{tiles: array<int, array<string, mixed>>}>  $rows
     * @return array<int, array{tiles: array<int, array<string, mixed>>}>
     */
    private function rowsForEditForm(array $rows): array
    {
        return array_map(function (array $row): array {
            return [
                'tiles' => array_map(function (array $tile): array {
                    $source = is_array($tile['source'] ?? null) ? $tile['source'] : [];

                    if (($source['type'] ?? null) !== 'payload_formula') {
                        return $tile;
                    }

                    return [
                        ...$tile,
                        'source' => [
                            ...$source,
                            'expression_json' => json_encode(
                                is_array($source['expression'] ?? null) ? $source['expression'] : [],
                                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                            ) ?: '[]',
                        ],
                    ];
                }, $row['tiles']),
            ];
        }, $rows);
    }

    /**
     * @param  array<int, mixed>|mixed  $rows
     * @param  array<string, array{
     *     label: string,
     *     compact_label: string,
     *     option_label: string,
     *     unit: string|null,
     *     default_color: string,
     *     is_counter: bool
     * }>  $parameterMetadata
     * @return array<int, array{tiles: array<int, array<string, mixed>>}>
     */
    private function normalizeStatusSummaryRows(mixed $rows, array $parameterMetadata): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $normalizedRows = [];
        $seen = [];
        $palette = [
            '#22d3ee',
            '#a855f7',
            '#f97316',
            '#10b981',
            '#f43f5e',
            '#3b82f6',
            '#f59e0b',
            '#14b8a6',
        ];
        $colorIndex = 0;

        foreach (array_values($rows) as $rowIndex => $row) {
            if (! is_array($row)) {
                continue;
            }

            $normalizedTiles = [];

            foreach (array_values(is_array($row['tiles'] ?? null) ? $row['tiles'] : []) as $tileIndex => $tile) {
                if (! is_array($tile)) {
                    continue;
                }

                $source = $this->normalizeStatusSummarySource(
                    source: is_array($tile['source'] ?? null) ? $tile['source'] : [],
                );
                $resolvedKey = $this->normalizeStatusSummaryTileKey($tile['key'] ?? null, $source);

                if ($resolvedKey === '' || in_array($resolvedKey, $seen, true)) {
                    continue;
                }

                $parameterKey = is_string($source['parameter_key'] ?? null)
                    ? $source['parameter_key']
                    : '';
                $metadata = $parameterKey !== '' ? ($parameterMetadata[$parameterKey] ?? null) : null;
                $normalizedTiles[] = [
                    'key' => $resolvedKey,
                    'label' => $metadata['compact_label'] ?? $this->defaultStatusSummaryTileLabel($source, $rowIndex, $tileIndex),
                    'unit' => $metadata['unit'] ?? null,
                    'base_color' => $metadata['default_color'] ?? $palette[$colorIndex % count($palette)],
                    'threshold_ranges' => $this->normalizeThresholdRanges($tile['threshold_ranges'] ?? []),
                    'source' => $source,
                ];
                $seen[] = $resolvedKey;
                $colorIndex++;
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
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function normalizeStatusSummarySource(array $source): array
    {
        $type = is_string($source['type'] ?? null) ? trim((string) $source['type']) : 'latest_parameter';

        return match ($type) {
            'payload_formula' => [
                'type' => 'payload_formula',
                'expression' => $this->normalizeJsonExpression($source['expression_json'] ?? $source['expression'] ?? null),
            ],
            'counter_window_delta' => [
                'type' => 'counter_window_delta',
                'parameter_key' => is_string($source['parameter_key'] ?? null) ? trim((string) $source['parameter_key']) : '',
                'window' => $this->normalizeCounterWindow($source['window'] ?? []),
            ],
            default => [
                'type' => 'latest_parameter',
                'parameter_key' => is_string($source['parameter_key'] ?? null) ? trim((string) $source['parameter_key']) : '',
            ],
        };
    }

    /**
     * @param  array<string, mixed>|mixed  $window
     * @return array<string, mixed>
     */
    private function normalizeCounterWindow(mixed $window): array
    {
        if (! is_array($window)) {
            return ['type' => 'today'];
        }

        $type = is_string($window['type'] ?? null) ? trim((string) $window['type']) : 'today';

        return match ($type) {
            'month_to_date' => ['type' => 'month_to_date'],
            'trailing_duration' => [
                'type' => 'trailing_duration',
                'minutes' => $this->toInt($window['minutes'] ?? null, 60),
            ],
            'completed_shift' => [
                'type' => 'completed_shift',
                'shift_schedule_id' => is_string($window['shift_schedule_id'] ?? null) ? trim((string) $window['shift_schedule_id']) : '',
                'offset' => $this->toInt($window['offset'] ?? null, 0),
            ],
            'completed_shift_span' => [
                'type' => 'completed_shift_span',
                'shift_schedule_id' => is_string($window['shift_schedule_id'] ?? null) ? trim((string) $window['shift_schedule_id']) : '',
                'offset' => $this->toInt($window['offset'] ?? null, 0),
                'count' => $this->toInt($window['count'] ?? null, 2),
            ],
            default => ['type' => 'today'],
        };
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function normalizeStatusSummaryTileKey(mixed $value, array $source): string
    {
        $sourceKey = $this->statusSummarySourceTileKey($source);

        if ($sourceKey !== '') {
            return $sourceKey;
        }

        if (is_string($value) && trim($value) !== '') {
            return trim((string) $value);
        }

        $sourceType = is_string($source['type'] ?? null) ? $source['type'] : 'tile';

        return Str::slug($sourceType, '_');
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function statusSummarySourceTileKey(array $source): string
    {
        $type = is_string($source['type'] ?? null) ? trim((string) $source['type']) : 'latest_parameter';
        $parameterKey = is_string($source['parameter_key'] ?? null) ? trim((string) $source['parameter_key']) : '';

        return match ($type) {
            'counter_window_delta' => $parameterKey === ''
                ? ''
                : "{$parameterKey}__{$this->statusSummaryCounterWindowKey($source['window'] ?? [])}",
            'payload_formula' => 'payload_formula__'.$this->statusSummaryExpressionHash($source['expression'] ?? []),
            default => $parameterKey,
        };
    }

    /**
     * @param  array<string, mixed>|mixed  $window
     */
    private function statusSummaryCounterWindowKey(mixed $window): string
    {
        if (! is_array($window)) {
            return 'today';
        }

        $type = is_string($window['type'] ?? null) ? trim((string) $window['type']) : 'today';
        $shiftScheduleId = $this->stringFromMixed($window['shift_schedule_id'] ?? null);

        return match ($type) {
            'month_to_date' => 'month_to_date',
            'trailing_duration' => 'trailing_duration_'.$this->toInt($window['minutes'] ?? null, 60).'m',
            'completed_shift' => implode('__', array_filter([
                'completed_shift',
                $this->slugStatusSummaryKeySegment($shiftScheduleId),
                'offset_'.$this->toInt($window['offset'] ?? null, 0),
            ])),
            'completed_shift_span' => implode('__', array_filter([
                'completed_shift_span',
                $this->slugStatusSummaryKeySegment($shiftScheduleId),
                'offset_'.$this->toInt($window['offset'] ?? null, 0),
                'count_'.$this->toInt($window['count'] ?? null, 2),
            ])),
            default => 'today',
        };
    }

    /**
     * @param  array<int|string, mixed>|mixed  $expression
     */
    private function statusSummaryExpressionHash(mixed $expression): string
    {
        $normalizedExpression = is_array($expression) ? $expression : [];
        $encodedExpression = json_encode($normalizedExpression, JSON_UNESCAPED_SLASHES);

        return substr(md5($encodedExpression ?: '[]'), 0, 12);
    }

    private function slugStatusSummaryKeySegment(string $value): string
    {
        $slug = Str::slug($value, '_');

        return $slug !== '' ? $slug : 'default';
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function defaultStatusSummaryTileLabel(array $source, int $rowIndex, int $tileIndex): string
    {
        $sourceType = is_string($source['type'] ?? null) ? $source['type'] : 'latest_parameter';

        if ($sourceType === 'payload_formula') {
            return 'Formula';
        }

        return Str::headline("tile_{$rowIndex}_{$tileIndex}");
    }

    /**
     * @param  array<int, mixed>|mixed  $ranges
     * @return array<int, array{from: int|float|null, to: int|float|null, color: string}>
     */
    private function normalizeThresholdRanges(mixed $ranges): array
    {
        if (! is_array($ranges)) {
            return [];
        }

        $normalizedRanges = [];

        foreach (array_values($ranges) as $range) {
            if (! is_array($range)) {
                continue;
            }

            $color = is_string($range['color'] ?? null) ? trim((string) $range['color']) : '';

            if ($color === '') {
                continue;
            }

            $normalizedRanges[] = [
                'from' => $this->normalizeNullableNumber($range['from'] ?? null),
                'to' => $this->normalizeNullableNumber($range['to'] ?? null),
                'color' => $color,
            ];
        }

        return $normalizedRanges;
    }

    private function normalizeNullableNumber(mixed $value): int|float|null
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && trim((string) $value) === '') {
            return null;
        }

        return is_numeric($value) ? $value + 0 : null;
    }

    /**
     * @return array<int|string, mixed>
     */
    private function normalizeJsonExpression(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
