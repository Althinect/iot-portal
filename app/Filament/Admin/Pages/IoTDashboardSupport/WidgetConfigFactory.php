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
use BackedEnum;

class WidgetConfigFactory
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array{key: string, label: string, color: string}>  $series
     */
    public function create(WidgetType $type, array $data, array $series): WidgetConfig
    {
        return $this->make($type, $data, $series, null);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array{key: string, label: string, color: string}>  $series
     */
    public function update(WidgetType $type, array $data, array $series, WidgetConfig $currentConfig): WidgetConfig
    {
        return $this->make($type, $data, $series, $currentConfig);
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

        if ($config instanceof StateTimelineConfig) {
            $data['state_mappings'] = $config->stateMappings();
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array{key: string, label: string, color: string}>  $series
     */
    private function make(
        WidgetType $type,
        array $data,
        array $series,
        ?WidgetConfig $currentConfig,
    ): WidgetConfig {
        $current = $currentConfig?->toArray() ?? [];
        $transport = is_array($current['transport'] ?? null) ? $current['transport'] : [];
        $window = is_array($current['window'] ?? null) ? $current['window'] : [];

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
}
