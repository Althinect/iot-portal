<?php

declare(strict_types=1);

namespace Database\Factories\Domain\IoTDashboard\Models;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Domain\IoTDashboard\Enums\WidgetType;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use App\Domain\IoTDashboard\Widgets\BarChart\BarInterval;
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
        return [
            'iot_dashboard_id' => IoTDashboardFactory::new(),
            'device_id' => Device::factory(),
            'schema_version_topic_id' => SchemaVersionTopic::factory()->publish(),
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
            'config' => [
                'series' => [
                    ['key' => 'total_energy_kwh', 'label' => 'Total Energy', 'color' => '#0ea5e9'],
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
                'bar_interval' => BarInterval::Hourly->value,
            ],
        ]);
    }

    public function statusSummary(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => WidgetType::StatusSummary->value,
            'config' => [
                'rows' => [
                    [
                        'tiles' => [
                            [
                                'key' => 'V1',
                                'label' => 'V1',
                                'base_color' => '#22d3ee',
                                'unit' => 'Volts',
                                'threshold_ranges' => [],
                                'source' => [
                                    'type' => 'latest_parameter',
                                    'parameter_key' => 'V1',
                                ],
                            ],
                            [
                                'key' => 'A1',
                                'label' => 'A1',
                                'base_color' => '#10b981',
                                'unit' => 'A',
                                'threshold_ranges' => [],
                                'source' => [
                                    'type' => 'latest_parameter',
                                    'parameter_key' => 'A1',
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
