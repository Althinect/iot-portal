<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Domain\IoTDashboard\Enums\WidgetType;
use App\Domain\IoTDashboard\Models\IoTDashboard;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use App\Domain\IoTDashboard\Widgets\StateCard\StateCardStyle;
use App\Domain\Shared\Models\Organization;
use Illuminate\Database\Seeder;

class WitcoDashboardSeeder extends Seeder
{
    /**
     * @var array<int, array{
     *     external_id: string,
     *     title: string,
     *     style: string,
     *     mappings: array<int, array{value: int, label: string, color: string}>
     * }>
     */
    private const STATUS_WIDGETS = [
        [
            'external_id' => 'witco-access-control-system-alarm',
            'title' => 'Access Control System Alarm',
            'style' => 'toggle',
            'mappings' => [
                ['value' => 0, 'label' => 'NORMAL', 'color' => '#22c55e'],
                ['value' => 1, 'label' => 'ALARM', 'color' => '#ef4444'],
            ],
        ],
        [
            'external_id' => 'witco-cctv-system-alarm',
            'title' => 'CCTV System Alarm',
            'style' => 'toggle',
            'mappings' => [
                ['value' => 0, 'label' => 'NORMAL', 'color' => '#22c55e'],
                ['value' => 1, 'label' => 'ALARM', 'color' => '#ef4444'],
            ],
        ],
        [
            'external_id' => 'witco-fire-alarm-panel',
            'title' => 'Fire Alarm Panel',
            'style' => 'toggle',
            'mappings' => [
                ['value' => 0, 'label' => 'NORMAL', 'color' => '#22c55e'],
                ['value' => 1, 'label' => 'ALARM', 'color' => '#ef4444'],
            ],
        ],
        [
            'external_id' => 'witco-ups-alarm-status',
            'title' => 'UPS Alarm Status',
            'style' => 'toggle',
            'mappings' => [
                ['value' => 0, 'label' => 'NORMAL', 'color' => '#22c55e'],
                ['value' => 1, 'label' => 'ALARM', 'color' => '#ef4444'],
            ],
        ],
        [
            'external_id' => 'witco-th-rh-input-server-room',
            'title' => 'TH & RH Input - Server room',
            'style' => 'toggle',
            'mappings' => [
                ['value' => 0, 'label' => 'OFF', 'color' => '#ef4444'],
                ['value' => 1, 'label' => 'ON', 'color' => '#22c55e'],
            ],
        ],
        [
            'external_id' => 'witco-water-tank-alarm-level',
            'title' => 'Water Tank Alarm Level',
            'style' => 'toggle',
            'mappings' => [
                ['value' => 0, 'label' => 'LOW', 'color' => '#ef4444'],
                ['value' => 1, 'label' => 'OK', 'color' => '#22c55e'],
            ],
        ],
        [
            'external_id' => 'witco-main-door-status',
            'title' => 'Main Door Status',
            'style' => 'pill',
            'mappings' => [
                ['value' => 0, 'label' => 'OPEN', 'color' => '#ef4444'],
                ['value' => 1, 'label' => 'CLOSED', 'color' => '#22c55e'],
            ],
        ],
        [
            'external_id' => 'witco-rear-door-status',
            'title' => 'Rear Door Status',
            'style' => 'pill',
            'mappings' => [
                ['value' => 0, 'label' => 'OPEN', 'color' => '#ef4444'],
                ['value' => 1, 'label' => 'CLOSED', 'color' => '#22c55e'],
            ],
        ],
        [
            'external_id' => 'witco-th-rh-gf-ups-room',
            'title' => 'TH & RH - GF UPS room',
            'style' => 'toggle',
            'mappings' => [
                ['value' => 0, 'label' => 'OFF', 'color' => '#ef4444'],
                ['value' => 1, 'label' => 'ON', 'color' => '#22c55e'],
            ],
        ],
    ];

    public function run(): void
    {
        $organization = Organization::query()
            ->where('slug', WitcoMigrationSeeder::ORGANIZATION_SLUG)
            ->first();

        if (! $organization instanceof Organization) {
            $this->command?->warn('WITCO organization not found. Skipping WITCO dashboard seed.');

            return;
        }

        $statusDashboard = IoTDashboard::query()->updateOrCreate(
            [
                'organization_id' => $organization->id,
                'slug' => 'witco-status-dashboard',
            ],
            [
                'name' => 'Status Dashboard',
                'is_active' => true,
                'refresh_interval_seconds' => 10,
            ],
        );

        $historyDashboard = IoTDashboard::query()->updateOrCreate(
            [
                'organization_id' => $organization->id,
                'slug' => 'witco-history-dashboard',
            ],
            [
                'name' => 'History Dashboard',
                'is_active' => true,
                'refresh_interval_seconds' => 15,
            ],
        );

        $this->syncWidgets(
            dashboard: $statusDashboard,
            widgetType: WidgetType::StateCard,
            layoutWidth: 4,
            layoutHeight: 4,
            cardHeightPixels: 320,
        );

        $this->syncWidgets(
            dashboard: $historyDashboard,
            widgetType: WidgetType::StateTimeline,
            layoutWidth: 12,
            layoutHeight: 5,
            cardHeightPixels: 340,
        );
    }

    private function syncWidgets(
        IoTDashboard $dashboard,
        WidgetType $widgetType,
        int $layoutWidth,
        int $layoutHeight,
        int $cardHeightPixels,
    ): void {
        $expectedTitles = [];

        foreach (array_values(self::STATUS_WIDGETS) as $index => $configuration) {
            $device = Device::query()
                ->where('organization_id', $dashboard->organization_id)
                ->where('external_id', $configuration['external_id'])
                ->first();

            if (! $device instanceof Device) {
                continue;
            }

            $topic = SchemaVersionTopic::query()
                ->where('device_schema_version_id', $device->device_schema_version_id)
                ->where('key', 'telemetry')
                ->first();

            if (! $topic instanceof SchemaVersionTopic) {
                continue;
            }

            $columnCount = intdiv(24, $layoutWidth);
            $x = ($index % max(1, $columnCount)) * $layoutWidth;
            $y = intdiv($index, max(1, $columnCount)) * $layoutHeight;

            $expectedTitles[] = $configuration['title'];

            IoTDashboardWidget::query()->updateOrCreate(
                [
                    'iot_dashboard_id' => $dashboard->id,
                    'title' => $configuration['title'],
                ],
                [
                    'device_id' => $device->id,
                    'schema_version_topic_id' => $topic->id,
                    'type' => $widgetType->value,
                    'config' => $widgetType === WidgetType::StateCard
                        ? [
                            'series' => [
                                ['key' => 'status', 'label' => 'Status', 'color' => $configuration['mappings'][0]['color']],
                            ],
                            'transport' => [
                                'use_websocket' => true,
                                'use_polling' => true,
                                'polling_interval_seconds' => 10,
                            ],
                            'window' => [
                                'lookback_minutes' => 1440,
                                'max_points' => 1,
                            ],
                            'display_style' => $configuration['style'] === 'pill'
                                ? StateCardStyle::Pill->value
                                : StateCardStyle::Toggle->value,
                            'state_mappings' => $configuration['mappings'],
                        ]
                        : [
                            'series' => [
                                ['key' => 'status', 'label' => 'Status', 'color' => $configuration['mappings'][0]['color']],
                            ],
                            'transport' => [
                                'use_websocket' => true,
                                'use_polling' => true,
                                'polling_interval_seconds' => 10,
                            ],
                            'window' => [
                                'lookback_minutes' => 360,
                                'max_points' => 240,
                            ],
                            'state_mappings' => $configuration['mappings'],
                        ],
                    'layout' => [
                        'x' => $x,
                        'y' => $y,
                        'w' => $layoutWidth,
                        'h' => $layoutHeight,
                        'columns' => 24,
                        'card_height_px' => $cardHeightPixels,
                    ],
                    'sequence' => $index + 1,
                ],
            );
        }

        IoTDashboardWidget::query()
            ->where('iot_dashboard_id', $dashboard->id)
            ->whereNotIn('title', $expectedTitles)
            ->delete();
    }
}
