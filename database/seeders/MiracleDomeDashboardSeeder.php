<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceSchema\Enums\MetricUnit;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Domain\IoTDashboard\Enums\WidgetType;
use App\Domain\IoTDashboard\Models\IoTDashboard;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use App\Domain\Shared\Models\Organization;
use Illuminate\Database\Seeder;

class MiracleDomeDashboardSeeder extends Seeder
{
    /**
     * @var array<int, array{external_id: string, title: string}>
     */
    private const DEVICE_WIDGETS = [
        [
            'external_id' => '869244041759261-21',
            'title' => 'Video Room 2 Energy Meter',
        ],
        [
            'external_id' => '869244041759402-21',
            'title' => 'Server Room 2 Energy meter',
        ],
        [
            'external_id' => '869244041759402-22',
            'title' => 'BTS Energy meter',
        ],
    ];

    public function run(): void
    {
        $organization = Organization::query()
            ->where('slug', MiracleDomeMigrationSeeder::ORGANIZATION_SLUG)
            ->first();

        if (! $organization instanceof Organization) {
            $this->command?->warn('Miracle Dome organization not found. Skipping Miracle Dome dashboard seed.');

            return;
        }

        $energyDashboard = IoTDashboard::query()->updateOrCreate(
            [
                'organization_id' => $organization->id,
                'slug' => 'miracle-dome-energy-dashboard',
            ],
            [
                'name' => 'Energy Dashboard',
                'description' => 'Latest energy status and realtime voltage trends for Miracle Dome energy meters.',
                'is_active' => true,
                'refresh_interval_seconds' => 10,
            ],
        );

        $historyDashboard = IoTDashboard::query()->updateOrCreate(
            [
                'organization_id' => $organization->id,
                'slug' => 'miracle-dome-energy-history-dashboard',
            ],
            [
                'name' => 'Energy History Dashboard',
                'description' => 'Daily energy consumption history for Miracle Dome meters.',
                'is_active' => true,
                'refresh_interval_seconds' => 120,
            ],
        );

        $this->syncRealtimeWidgets($energyDashboard);
        $this->syncHistoryWidgets($historyDashboard);
    }

    private function syncRealtimeWidgets(IoTDashboard $dashboard): void
    {
        $expectedTitles = [];

        foreach (array_values(self::DEVICE_WIDGETS) as $index => $configuration) {
            $device = $this->resolveDevice($dashboard->organization_id, $configuration['external_id']);
            $topic = $device?->schemaVersion?->topics()->where('key', 'telemetry')->first();

            if (! $device instanceof Device || ! $topic instanceof SchemaVersionTopic) {
                continue;
            }

            $expectedTitles[] = $configuration['title'];
            $expectedTitles[] = $configuration['title'].' Status';

            IoTDashboardWidget::query()->updateOrCreate(
                [
                    'iot_dashboard_id' => $dashboard->id,
                    'title' => $configuration['title'].' Status',
                ],
                [
                    'device_id' => $device->id,
                    'schema_version_topic_id' => $topic->id,
                    'type' => WidgetType::StatusSummary->value,
                    'config' => [
                        'rows' => [
                            [
                                'tiles' => [[
                                    'key' => 'TotalEnergy',
                                    'label' => 'Total kWh',
                                    'base_color' => '#0ea5e9',
                                    'unit' => MetricUnit::KilowattHours->value,
                                    'threshold_ranges' => [],
                                    'source' => [
                                        'type' => 'latest_parameter',
                                        'parameter_key' => 'TotalEnergy',
                                    ],
                                ]],
                            ],
                            [
                                'tiles' => [
                                    [
                                        'key' => 'PhaseAVoltage',
                                        'label' => 'Phase A V',
                                        'base_color' => '#22d3ee',
                                        'unit' => MetricUnit::Volts->value,
                                        'threshold_ranges' => [],
                                        'source' => [
                                            'type' => 'latest_parameter',
                                            'parameter_key' => 'PhaseAVoltage',
                                        ],
                                    ],
                                    [
                                        'key' => 'PhaseBVoltage',
                                        'label' => 'Phase B V',
                                        'base_color' => '#3b82f6',
                                        'unit' => MetricUnit::Volts->value,
                                        'threshold_ranges' => [],
                                        'source' => [
                                            'type' => 'latest_parameter',
                                            'parameter_key' => 'PhaseBVoltage',
                                        ],
                                    ],
                                    [
                                        'key' => 'PhaseCVoltage',
                                        'label' => 'Phase C V',
                                        'base_color' => '#8b5cf6',
                                        'unit' => MetricUnit::Volts->value,
                                        'threshold_ranges' => [],
                                        'source' => [
                                            'type' => 'latest_parameter',
                                            'parameter_key' => 'PhaseCVoltage',
                                        ],
                                    ],
                                ],
                            ],
                            [
                                'tiles' => [
                                    [
                                        'key' => 'PhaseACurrent',
                                        'label' => 'Phase A I',
                                        'base_color' => '#10b981',
                                        'unit' => MetricUnit::Amperes->value,
                                        'threshold_ranges' => [],
                                        'source' => [
                                            'type' => 'latest_parameter',
                                            'parameter_key' => 'PhaseACurrent',
                                        ],
                                    ],
                                    [
                                        'key' => 'PhaseBCurrent',
                                        'label' => 'Phase B I',
                                        'base_color' => '#14b8a6',
                                        'unit' => MetricUnit::Amperes->value,
                                        'threshold_ranges' => [],
                                        'source' => [
                                            'type' => 'latest_parameter',
                                            'parameter_key' => 'PhaseBCurrent',
                                        ],
                                    ],
                                    [
                                        'key' => 'PhaseCCurrent',
                                        'label' => 'Phase C I',
                                        'base_color' => '#f59e0b',
                                        'unit' => MetricUnit::Amperes->value,
                                        'threshold_ranges' => [],
                                        'source' => [
                                            'type' => 'latest_parameter',
                                            'parameter_key' => 'PhaseCCurrent',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'transport' => [
                            'use_websocket' => true,
                            'use_polling' => true,
                            'polling_interval_seconds' => 10,
                        ],
                        'window' => [
                            'lookback_minutes' => 180,
                            'max_points' => 1,
                        ],
                    ],
                    'layout' => [
                        'x' => $index * 8,
                        'y' => 0,
                        'w' => 8,
                        'h' => 4,
                        'columns' => 24,
                        'card_height_px' => 320,
                    ],
                    'sequence' => $index + 1,
                ],
            );

            IoTDashboardWidget::query()->updateOrCreate(
                [
                    'iot_dashboard_id' => $dashboard->id,
                    'title' => $configuration['title'],
                ],
                [
                    'device_id' => $device->id,
                    'schema_version_topic_id' => $topic->id,
                    'type' => WidgetType::LineChart->value,
                    'config' => [
                        'series' => [
                            ['key' => 'PhaseAVoltage', 'label' => 'Phase A Voltage', 'color' => '#22d3ee'],
                            ['key' => 'PhaseBVoltage', 'label' => 'Phase B Voltage', 'color' => '#3b82f6'],
                            ['key' => 'PhaseCVoltage', 'label' => 'Phase C Voltage', 'color' => '#8b5cf6'],
                        ],
                        'transport' => [
                            'use_websocket' => true,
                            'use_polling' => true,
                            'polling_interval_seconds' => 10,
                        ],
                        'window' => [
                            'lookback_minutes' => 120,
                            'max_points' => 240,
                        ],
                    ],
                    'layout' => [
                        'x' => $index * 8,
                        'y' => 4,
                        'w' => 8,
                        'h' => 5,
                        'columns' => 24,
                        'card_height_px' => 360,
                    ],
                    'sequence' => $index + 1 + count(self::DEVICE_WIDGETS),
                ],
            );
        }

        IoTDashboardWidget::query()
            ->where('iot_dashboard_id', $dashboard->id)
            ->whereNotIn('title', $expectedTitles)
            ->delete();
    }

    private function syncHistoryWidgets(IoTDashboard $dashboard): void
    {
        $expectedTitles = [];

        foreach (array_values(self::DEVICE_WIDGETS) as $index => $configuration) {
            $device = $this->resolveDevice($dashboard->organization_id, $configuration['external_id']);
            $topic = $device?->schemaVersion?->topics()->where('key', 'telemetry')->first();

            if (! $device instanceof Device || ! $topic instanceof SchemaVersionTopic) {
                continue;
            }

            $title = $configuration['title'].' Consumption';
            $expectedTitles[] = $title;

            IoTDashboardWidget::query()->updateOrCreate(
                [
                    'iot_dashboard_id' => $dashboard->id,
                    'title' => $title,
                ],
                [
                    'device_id' => $device->id,
                    'schema_version_topic_id' => $topic->id,
                    'type' => WidgetType::BarChart->value,
                    'config' => [
                        'series' => [
                            ['key' => 'TotalEnergy', 'label' => 'Total Energy', 'color' => '#0ea5e9'],
                        ],
                        'transport' => [
                            'use_websocket' => false,
                            'use_polling' => true,
                            'polling_interval_seconds' => 120,
                        ],
                        'window' => [
                            'lookback_minutes' => 43200,
                            'max_points' => 31,
                        ],
                        'bar_interval' => 'daily',
                    ],
                    'layout' => [
                        'x' => $index * 8,
                        'y' => 0,
                        'w' => 8,
                        'h' => 5,
                        'columns' => 24,
                        'card_height_px' => 360,
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

    private function resolveDevice(int $organizationId, string $externalId): ?Device
    {
        return Device::query()
            ->where('organization_id', $organizationId)
            ->where('external_id', $externalId)
            ->first();
    }
}
