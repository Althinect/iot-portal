<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Enums\ChannelDirection;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use App\Domain\IoTDashboard\Enums\WidgetType;
use App\Domain\IoTDashboard\Models\IoTDashboard;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use App\Domain\Shared\Models\Organization;
use Illuminate\Database\Seeder;

class IoTDashboardSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $organization = Organization::query()->orderBy('id')->first();

        if (! $organization instanceof Organization) {
            $this->command?->warn('No organization found. Skipping IoT dashboard seed.');

            return;
        }

        $telemetryChannel = DeviceChannel::query()
            ->where('direction', ChannelDirection::Publish->value)
            ->where('key', 'telemetry')
            ->whereHas('profileVersion.profile', fn ($query) => $query->where('key', 'energy_meter'))
            ->orderBy('id')
            ->first();

        if (! $telemetryChannel instanceof DeviceChannel) {
            $this->command?->warn('Energy meter telemetry channel not found. Run profile seeders first.');

            return;
        }

        $dashboard = IoTDashboard::query()->firstOrCreate(
            [
                'organization_id' => $organization->id,
                'slug' => 'energy-meter-dashboard',
            ],
            [
                'name' => 'Energy Meter Dashboard',
                'description' => 'Realtime and historical voltage monitoring for the main energy meter.',
                'refresh_interval_seconds' => 10,
                'is_active' => true,
            ],
        );

        $device = Device::query()
            ->where('organization_id', $organization->id)
            ->where('device_profile_version_id', $telemetryChannel->device_profile_version_id)
            ->orderBy('id')
            ->first();

        if (! $device instanceof Device) {
            $this->command?->warn('No compatible device found for energy meter telemetry channel. Skipping widget seed.');

            return;
        }

        $parameterLabels = ProfileParameterDefinition::query()
            ->where('device_channel_id', $telemetryChannel->id)
            ->whereIn('key', ['V1', 'V2', 'V3', 'A1', 'total_energy_kwh'])
            ->pluck('label', 'key')
            ->all();

        $seriesConfiguration = [
            [
                'key' => 'V1',
                'label' => (string) ($parameterLabels['V1'] ?? 'V1'),
                'color' => '#22d3ee',
            ],
            [
                'key' => 'V2',
                'label' => (string) ($parameterLabels['V2'] ?? 'V2'),
                'color' => '#a855f7',
            ],
            [
                'key' => 'V3',
                'label' => (string) ($parameterLabels['V3'] ?? 'V3'),
                'color' => '#f97316',
            ],
        ];

        IoTDashboardWidget::query()->updateOrCreate(
            [
                'iot_dashboard_id' => $dashboard->id,
                'device_channel_id' => $telemetryChannel->id,
                'title' => 'Energy Meter Voltages (V1 / V2 / V3)',
            ],
            [
                'type' => WidgetType::LineChart->value,
                'device_id' => $device->id,
                'device_channel_id' => $telemetryChannel->id,
                'config' => [
                    'series' => $seriesConfiguration,
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
                    'x' => 0,
                    'y' => 0,
                    'w' => 12,
                    'h' => 4,
                    'columns' => 24,
                    'card_height_px' => 384,
                ],
                'sequence' => 1,
            ],
        );

        IoTDashboardWidget::query()->updateOrCreate(
            [
                'iot_dashboard_id' => $dashboard->id,
                'device_channel_id' => $telemetryChannel->id,
                'title' => 'Hourly Energy Consumption (kWh)',
            ],
            [
                'type' => WidgetType::BarChart->value,
                'device_id' => $device->id,
                'device_channel_id' => $telemetryChannel->id,
                'config' => [
                    'series' => [
                        [
                            'key' => 'total_energy_kwh',
                            'label' => (string) ($parameterLabels['total_energy_kwh'] ?? 'Total Energy (kWh)'),
                            'color' => '#0ea5e9',
                        ],
                    ],
                    'transport' => [
                        'use_websocket' => false,
                        'use_polling' => true,
                        'polling_interval_seconds' => 60,
                    ],
                    'window' => [
                        'lookback_minutes' => 1440,
                        'max_points' => 24,
                    ],
                    'bar_interval' => 'hourly',
                ],
                'layout' => [
                    'x' => 0,
                    'y' => 4,
                    'w' => 12,
                    'h' => 4,
                    'columns' => 24,
                    'card_height_px' => 384,
                ],
                'sequence' => 2,
            ],
        );

        IoTDashboardWidget::query()->updateOrCreate(
            [
                'iot_dashboard_id' => $dashboard->id,
                'device_channel_id' => $telemetryChannel->id,
                'title' => 'Daily Energy Consumption (kWh)',
            ],
            [
                'type' => WidgetType::BarChart->value,
                'device_id' => $device->id,
                'device_channel_id' => $telemetryChannel->id,
                'config' => [
                    'series' => [
                        [
                            'key' => 'total_energy_kwh',
                            'label' => (string) ($parameterLabels['total_energy_kwh'] ?? 'Total Energy (kWh)'),
                            'color' => '#22c55e',
                        ],
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
                    'x' => 12,
                    'y' => 4,
                    'w' => 12,
                    'h' => 4,
                    'columns' => 24,
                    'card_height_px' => 384,
                ],
                'sequence' => 3,
            ],
        );

        IoTDashboardWidget::query()->updateOrCreate(
            [
                'iot_dashboard_id' => $dashboard->id,
                'device_channel_id' => $telemetryChannel->id,
                'title' => 'Phase A Current Gauge (A1)',
            ],
            [
                'type' => WidgetType::GaugeChart->value,
                'device_id' => $device->id,
                'device_channel_id' => $telemetryChannel->id,
                'config' => [
                    'series' => [
                        [
                            'key' => 'A1',
                            'label' => (string) ($parameterLabels['A1'] ?? 'Current A1'),
                            'color' => '#3b82f6',
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
                    'gauge_style' => 'classic',
                    'gauge_min' => 0,
                    'gauge_max' => 120,
                    'gauge_ranges' => [
                        ['from' => 0, 'to' => 60, 'color' => '#10b981'],
                        ['from' => 60, 'to' => 90, 'color' => '#f59e0b'],
                        ['from' => 90, 'to' => 120, 'color' => '#ef4444'],
                    ],
                ],
                'layout' => [
                    'x' => 0,
                    'y' => 8,
                    'w' => 8,
                    'h' => 4,
                    'columns' => 24,
                    'card_height_px' => 384,
                ],
                'sequence' => 4,
            ],
        );
    }
}
