<?php

declare(strict_types=1);

namespace App\Domain\IoTDashboard\Widgets\StateTimeline;

use App\Domain\IoTDashboard\Contracts\WidgetDefinition;
use App\Domain\IoTDashboard\Enums\WidgetType;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;

class StateTimelineWidgetDefinition implements WidgetDefinition
{
    public function __construct(
        private readonly StateTimelineSnapshotResolver $snapshotResolver,
    ) {}

    public function type(): WidgetType
    {
        return WidgetType::StateTimeline;
    }

    public function makeConfig(array $config): StateTimelineConfig
    {
        return StateTimelineConfig::fromArray($config);
    }

    public function resolveSnapshot(IoTDashboardWidget $widget): array
    {
        return $this->snapshotResolver->resolve($widget, $this->makeConfig($widget->configArray()));
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
            'state_mappings' => $config->stateMappings(),
        ];
    }
}
