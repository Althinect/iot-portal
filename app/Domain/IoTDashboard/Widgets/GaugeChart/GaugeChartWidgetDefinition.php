<?php

declare(strict_types=1);

namespace App\Domain\IoTDashboard\Widgets\GaugeChart;

use App\Domain\IoTDashboard\Application\DashboardHistoryRange;
use App\Domain\IoTDashboard\Contracts\WidgetDefinition;
use App\Domain\IoTDashboard\Enums\WidgetType;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;

class GaugeChartWidgetDefinition implements WidgetDefinition
{
    public function __construct(
        private readonly GaugeChartSnapshotResolver $snapshotResolver,
    ) {}

    public function type(): WidgetType
    {
        return WidgetType::GaugeChart;
    }

    public function makeConfig(array $config): GaugeChartConfig
    {
        return GaugeChartConfig::fromArray($config);
    }

    public function resolveSnapshot(IoTDashboardWidget $widget, ?DashboardHistoryRange $historyRange = null): array
    {
        return $this->snapshotResolver->resolve($widget, $this->makeConfig($widget->configArray()), $historyRange);
    }

    public function bootstrapPayload(IoTDashboardWidget $widget): array
    {
        $config = $this->makeConfig($widget->configArray());

        return [
            'series' => $config->series(),
            'use_websocket' => $config->useWebsocket(),
            'use_polling' => $config->usePolling(),
            'polling_interval_seconds' => $config->pollingIntervalSeconds(),
            'lookback_minutes' => $config->lookbackMinutes(),
            'max_points' => $config->maxPoints(),
            'gauge_style' => $config->gaugeStyle()->value,
            'gauge_min' => $config->gaugeMinimum(),
            'gauge_max' => $config->gaugeMaximum(),
            'gauge_ranges' => $config->gaugeRanges(),
        ];
    }
}
