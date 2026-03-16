<?php

declare(strict_types=1);

namespace App\Domain\IoTDashboard\Widgets\StateCard;

use App\Domain\IoTDashboard\Application\DashboardHistoryRange;
use App\Domain\IoTDashboard\Contracts\WidgetDefinition;
use App\Domain\IoTDashboard\Enums\WidgetType;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;

class StateCardWidgetDefinition implements WidgetDefinition
{
    public function __construct(
        private readonly StateCardSnapshotResolver $snapshotResolver,
    ) {}

    public function type(): WidgetType
    {
        return WidgetType::StateCard;
    }

    public function makeConfig(array $config): StateCardConfig
    {
        return StateCardConfig::fromArray($config);
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
            'display_style' => $config->displayStyle()->value,
            'state_mappings' => $config->stateMappings(),
        ];
    }
}
