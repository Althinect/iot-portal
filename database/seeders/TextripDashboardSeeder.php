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

class TextripDashboardSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::query()
            ->where('slug', TextripMigrationSeeder::ORGANIZATION_SLUG)
            ->first();

        if (! $organization instanceof Organization) {
            $this->command?->warn('Textrip organization not found. Skipping Textrip dashboard seed.');

            return;
        }

        $energyOverview = IoTDashboard::query()->updateOrCreate(
            [
                'organization_id' => $organization->id,
                'slug' => 'textrip-energy-overview',
            ],
            [
                'name' => 'Energy Overview',
                'description' => 'Latest energy status for the Textrip AC energy devices.',
                'is_active' => true,
                'refresh_interval_seconds' => 15,
            ],
        );

        $energyHistory = IoTDashboard::query()->updateOrCreate(
            [
                'organization_id' => $organization->id,
                'slug' => 'textrip-energy-history',
            ],
            [
                'name' => 'Energy History',
                'description' => 'Daily energy history for the Textrip AC energy devices.',
                'is_active' => true,
                'refresh_interval_seconds' => 120,
            ],
        );

        $tanksOverview = IoTDashboard::query()->updateOrCreate(
            [
                'organization_id' => $organization->id,
                'slug' => 'textrip-tanks-overview',
            ],
            [
                'name' => 'Tanks Overview',
                'description' => 'Latest storage levels for fuel and water assets.',
                'is_active' => true,
                'refresh_interval_seconds' => 30,
            ],
        );

        $tanksHistory = IoTDashboard::query()->updateOrCreate(
            [
                'organization_id' => $organization->id,
                'slug' => 'textrip-tanks-history',
            ],
            [
                'name' => 'Tanks History',
                'description' => 'Level history for the Textrip tank devices.',
                'is_active' => true,
                'refresh_interval_seconds' => 180,
            ],
        );

        $energyDevices = Device::query()
            ->where('organization_id', $organization->id)
            ->whereNotNull('parent_device_id')
            ->whereHas('deviceType', fn ($query) => $query->where('key', 'energy_meter'))
            ->orderBy('external_id')
            ->get();

        $tankDevices = Device::query()
            ->where('organization_id', $organization->id)
            ->whereNotNull('parent_device_id')
            ->whereHas('deviceType', fn ($query) => $query->where('key', 'tank_level_sensor'))
            ->orderBy('external_id')
            ->get();

        $this->syncEnergyOverviewWidgets($energyOverview, $energyDevices->all());
        $this->syncEnergyHistoryWidgets($energyHistory, $energyDevices->all());
        $this->syncTankOverviewWidgets($tanksOverview, $tankDevices->all());
        $this->syncTankHistoryWidgets($tanksHistory, $tankDevices->all());
    }

    /**
     * @param  array<int, Device>  $devices
     */
    private function syncEnergyOverviewWidgets(IoTDashboard $dashboard, array $devices): void
    {
        $expectedTitles = [];

        foreach (array_values($devices) as $index => $device) {
            $topic = $this->resolveTelemetryTopic($device);

            if (! $topic instanceof SchemaVersionTopic) {
                continue;
            }

            $title = $device->name;
            $expectedTitles[] = $title;

            IoTDashboardWidget::query()->updateOrCreate(
                [
                    'iot_dashboard_id' => $dashboard->id,
                    'title' => $title,
                ],
                [
                    'device_id' => $device->id,
                    'schema_version_topic_id' => $topic->id,
                    'type' => WidgetType::StatusSummary->value,
                    'config' => $this->energyOverviewConfig(),
                    'layout' => $this->layoutFor($index, 8, 4, 320),
                    'sequence' => $index + 1,
                ],
            );
        }

        IoTDashboardWidget::query()
            ->where('iot_dashboard_id', $dashboard->id)
            ->whereNotIn('title', $expectedTitles)
            ->delete();
    }

    /**
     * @param  array<int, Device>  $devices
     */
    private function syncEnergyHistoryWidgets(IoTDashboard $dashboard, array $devices): void
    {
        $expectedTitles = [];

        foreach (array_values($devices) as $index => $device) {
            $topic = $this->resolveTelemetryTopic($device);

            if (! $topic instanceof SchemaVersionTopic) {
                continue;
            }

            $title = $device->name.' History';
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
                    'layout' => $this->layoutFor($index, 8, 5, 360),
                    'sequence' => $index + 1,
                ],
            );
        }

        IoTDashboardWidget::query()
            ->where('iot_dashboard_id', $dashboard->id)
            ->whereNotIn('title', $expectedTitles)
            ->delete();
    }

    /**
     * @param  array<int, Device>  $devices
     */
    private function syncTankOverviewWidgets(IoTDashboard $dashboard, array $devices): void
    {
        $expectedTitles = [];

        foreach (array_values($devices) as $index => $device) {
            $topic = $this->resolveTelemetryTopic($device);

            if (! $topic instanceof SchemaVersionTopic) {
                continue;
            }

            $title = $device->name;
            $expectedTitles[] = $title;

            IoTDashboardWidget::query()->updateOrCreate(
                [
                    'iot_dashboard_id' => $dashboard->id,
                    'title' => $title,
                ],
                [
                    'device_id' => $device->id,
                    'schema_version_topic_id' => $topic->id,
                    'type' => WidgetType::StatusSummary->value,
                    'config' => [
                        'rows' => [
                            [
                                'tiles' => [[
                                    'key' => 'ioid1',
                                    'label' => 'Liquid Level',
                                    'base_color' => '#0ea5e9',
                                    'unit' => MetricUnit::Litres->value,
                                    'threshold_ranges' => [],
                                    'source' => [
                                        'type' => 'latest_parameter',
                                        'parameter_key' => 'ioid1',
                                    ],
                                ]],
                            ],
                        ],
                        'transport' => [
                            'use_websocket' => true,
                            'use_polling' => true,
                            'polling_interval_seconds' => 30,
                        ],
                        'window' => [
                            'lookback_minutes' => 360,
                            'max_points' => 1,
                        ],
                    ],
                    'layout' => $this->layoutFor($index, 8, 3, 260),
                    'sequence' => $index + 1,
                ],
            );
        }

        IoTDashboardWidget::query()
            ->where('iot_dashboard_id', $dashboard->id)
            ->whereNotIn('title', $expectedTitles)
            ->delete();
    }

    /**
     * @param  array<int, Device>  $devices
     */
    private function syncTankHistoryWidgets(IoTDashboard $dashboard, array $devices): void
    {
        $expectedTitles = [];

        foreach (array_values($devices) as $index => $device) {
            $topic = $this->resolveTelemetryTopic($device);

            if (! $topic instanceof SchemaVersionTopic) {
                continue;
            }

            $title = $device->name.' History';
            $expectedTitles[] = $title;

            IoTDashboardWidget::query()->updateOrCreate(
                [
                    'iot_dashboard_id' => $dashboard->id,
                    'title' => $title,
                ],
                [
                    'device_id' => $device->id,
                    'schema_version_topic_id' => $topic->id,
                    'type' => WidgetType::LineChart->value,
                    'config' => [
                        'series' => [
                            ['key' => 'ioid1', 'label' => 'Liquid Level', 'color' => '#0ea5e9'],
                        ],
                        'transport' => [
                            'use_websocket' => false,
                            'use_polling' => true,
                            'polling_interval_seconds' => 120,
                        ],
                        'window' => [
                            'lookback_minutes' => 10080,
                            'max_points' => 240,
                        ],
                    ],
                    'layout' => $this->layoutFor($index, 12, 4, 300),
                    'sequence' => $index + 1,
                ],
            );
        }

        IoTDashboardWidget::query()
            ->where('iot_dashboard_id', $dashboard->id)
            ->whereNotIn('title', $expectedTitles)
            ->delete();
    }

    /**
     * @return array<string, mixed>
     */
    private function energyOverviewConfig(): array
    {
        return [
            'rows' => [
                [
                    'tiles' => [[
                        'key' => 'TotalEnergy',
                        'label' => 'Total Energy',
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
                            'key' => 'TotalActivePower',
                            'label' => 'Active Power',
                            'base_color' => '#10b981',
                            'unit' => MetricUnit::Watts->value,
                            'threshold_ranges' => [],
                            'source' => [
                                'type' => 'latest_parameter',
                                'parameter_key' => 'TotalActivePower',
                            ],
                        ],
                        [
                            'key' => 'TotalReactivePower',
                            'label' => 'Reactive Power',
                            'base_color' => '#14b8a6',
                            'unit' => MetricUnit::Watts->value,
                            'threshold_ranges' => [],
                            'source' => [
                                'type' => 'latest_parameter',
                                'parameter_key' => 'TotalReactivePower',
                            ],
                        ],
                        [
                            'key' => 'totalPowerFactor',
                            'label' => 'Power Factor',
                            'base_color' => '#f59e0b',
                            'unit' => '',
                            'threshold_ranges' => [],
                            'source' => [
                                'type' => 'latest_parameter',
                                'parameter_key' => 'totalPowerFactor',
                            ],
                        ],
                    ],
                ],
            ],
            'transport' => [
                'use_websocket' => true,
                'use_polling' => true,
                'polling_interval_seconds' => 15,
            ],
            'window' => [
                'lookback_minutes' => 180,
                'max_points' => 1,
            ],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function layoutFor(int $index, int $width, int $height, int $cardHeightPixels): array
    {
        $columnCount = max(1, intdiv(24, $width));

        return [
            'x' => ($index % $columnCount) * $width,
            'y' => intdiv($index, $columnCount) * $height,
            'w' => $width,
            'h' => $height,
            'columns' => 24,
            'card_height_px' => $cardHeightPixels,
        ];
    }

    private function resolveTelemetryTopic(Device $device): ?SchemaVersionTopic
    {
        return $device->schemaVersion?->topics()->where('key', 'telemetry')->first();
    }
}
