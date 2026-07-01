<?php

declare(strict_types=1);

namespace Database\Factories\Domain\IoTDashboard\Models;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\IoTDashboard\Enums\WidgetType;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use App\Domain\IoTDashboard\Widgets\StateCard\StateCardStyle;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IoTDashboardWidget>
 */
class IoTDashboardWidgetFactory extends Factory
{
    protected $model = IoTDashboardWidget::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $device = Device::factory()->create();
        $channel = DeviceChannel::factory()
            ->publish()
            ->create(['device_profile_version_id' => $device->device_profile_version_id]);

        return [
            'iot_dashboard_id' => IoTDashboardFactory::new(),
            'device_id' => $device->id,
            'device_channel_id' => $channel->id,
            'type' => WidgetType::LineChart->value,
            'title' => Str::title($this->faker->words(3, true)),
            'config' => [
                'series' => [
                    ['key' => 'value', 'label' => 'Value', 'color' => '#38bdf8'],
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
                'x' => 0,
                'y' => 0,
                'w' => 6,
                'h' => 4,
                'columns' => 24,
                'card_height_px' => 384,
            ],
            'sequence' => 0,
        ];
    }

    public function barChart(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => WidgetType::BarChart->value,
        ]);
    }

    public function statusSummary(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => WidgetType::StatusSummary->value,
            'config' => [
                'series' => [
                    ['key' => 'status', 'label' => 'Status', 'color' => '#22c55e'],
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
            ],
        ]);
    }

    public function stateCard(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => WidgetType::StateCard->value,
            'config' => [
                'series' => [
                    ['key' => 'status', 'label' => 'Status', 'color' => '#22c55e'],
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
                'display_style' => StateCardStyle::Toggle->value,
                'state_mappings' => [
                    ['value' => '0', 'label' => 'OFF', 'color' => '#ef4444'],
                    ['value' => '1', 'label' => 'ON', 'color' => '#22c55e'],
                ],
            ],
        ]);
    }

    public function stateTimeline(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => WidgetType::StateTimeline->value,
            'config' => [
                'series' => [
                    ['key' => 'status', 'label' => 'Status', 'color' => '#22c55e'],
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
                'state_mappings' => [
                    ['value' => '0', 'label' => 'OFF', 'color' => '#ef4444'],
                    ['value' => '1', 'label' => 'ON', 'color' => '#22c55e'],
                ],
            ],
        ]);
    }
}
