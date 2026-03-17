<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\IoTDashboardSupport;

use App\Domain\IoTDashboard\Application\RealtimeStreamChannel;
use App\Domain\IoTDashboard\Application\WidgetRegistry;
use App\Domain\IoTDashboard\Enums\WidgetType;
use App\Domain\IoTDashboard\Models\IoTDashboard;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;

class WidgetBootstrapPayloadBuilder
{
    public function __construct(
        private readonly WidgetRegistry $widgetRegistry,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function build(IoTDashboard $dashboard): array
    {
        return $dashboard->widgets
            ->map(function (IoTDashboardWidget $widget) use ($dashboard): array {
                $definition = $this->widgetRegistry->forWidget($widget);
                $bootstrapPayload = $definition->bootstrapPayload($widget);
                $realtimeChannel = RealtimeStreamChannel::forWidget($widget);

                return [
                    'id' => (int) $widget->id,
                    'type' => (string) $widget->type,
                    'title' => (string) $widget->title,
                    'topic' => [
                        'id' => (int) $widget->schema_version_topic_id,
                        'label' => $widget->topic?->label,
                        'suffix' => $widget->topic?->suffix,
                    ],
                    'device' => [
                        'id' => $widget->device_id === null ? null : (int) $widget->device_id,
                        'uuid' => $widget->device?->uuid,
                        'name' => $widget->device?->name,
                    ],
                    'device_connection_state' => $widget->device?->effectiveConnectionState(),
                    'device_last_seen_at' => $widget->device?->lastSeenAt()?->toIso8601String(),
                    'realtime' => $realtimeChannel === null || ! (bool) data_get($bootstrapPayload, 'use_websocket')
                        ? null
                        : [
                            'channel' => $realtimeChannel,
                            'sample_window_seconds' => $this->sampleWindowSecondsFor($widget),
                        ],
                    ...$bootstrapPayload,
                    'snapshot_url' => route('admin.iot-dashboard.dashboards.snapshots', [
                        'dashboard' => $dashboard,
                        'widget' => $widget->id,
                    ]),
                    'layout_url' => route('admin.iot-dashboard.dashboards.widgets.layout', [
                        'dashboard' => $dashboard,
                        'widget' => $widget,
                    ]),
                    'layout' => $widget->layoutArray(),
                ];
            })
            ->values()
            ->all();
    }

    private function sampleWindowSecondsFor(IoTDashboardWidget $widget): int
    {
        return match ($widget->widgetType()) {
            WidgetType::LineChart => 2,
            WidgetType::GaugeChart => 1,
            WidgetType::BarChart => 0,
            WidgetType::StatusSummary => 1,
            WidgetType::StateCard => 1,
            WidgetType::StateTimeline => 2,
        };
    }
}
