<?php

declare(strict_types=1);

namespace App\Domain\IoTDashboard\Widgets\StatusSummary;

use App\Domain\IoTDashboard\Application\DashboardHistoryRange;
use App\Domain\IoTDashboard\Contracts\WidgetDefinition;
use App\Domain\IoTDashboard\Enums\WidgetType;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;

class StatusSummaryWidgetDefinition implements WidgetDefinition
{
    public function __construct(
        private readonly StatusSummarySnapshotResolver $snapshotResolver,
    ) {}

    public function type(): WidgetType
    {
        return WidgetType::StatusSummary;
    }

    public function makeConfig(array $config): StatusSummaryConfig
    {
        return StatusSummaryConfig::fromArray($config);
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
            'layout_rows' => $config->layoutRows(),
            'use_websocket' => $config->supportsRealtime() && $config->useWebsocket(),
            'use_polling' => $config->usePolling(),
            'polling_interval_seconds' => $config->pollingIntervalSeconds(),
            'lookback_minutes' => $config->lookbackMinutes(),
            'max_points' => $config->maxPoints(),
        ];
    }
}
