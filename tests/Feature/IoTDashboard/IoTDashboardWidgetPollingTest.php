<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Models\DeviceType;
use App\Domain\DeviceSchema\Enums\ParameterCategory;
use App\Domain\DeviceSchema\Enums\ParameterDataType;
use App\Domain\DeviceSchema\Models\DeviceSchema;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Domain\DeviceSchema\Models\ParameterDefinition;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Domain\IoTDashboard\Models\IoTDashboard;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use App\Domain\Telemetry\Enums\ValidationStatus;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createDashboardSnapshotBaseContext(): array
{
    $organization = Organization::factory()->create();
    $deviceType = DeviceType::factory()->mqtt()->create([
        'key' => 'energy_meter_widget_test',
    ]);
    $schema = DeviceSchema::factory()->forDeviceType($deviceType)->create([
        'name' => 'Energy Meter Test Schema',
    ]);
    $schemaVersion = DeviceSchemaVersion::factory()->active()->create([
        'device_schema_id' => $schema->id,
        'version' => 1,
    ]);
    $topic = SchemaVersionTopic::factory()->publish()->create([
        'device_schema_version_id' => $schemaVersion->id,
        'key' => 'telemetry',
        'suffix' => 'telemetry',
        'label' => 'Telemetry',
    ]);

    foreach (['V1', 'V2', 'V3'] as $sequence => $key) {
        ParameterDefinition::factory()->create([
            'schema_version_topic_id' => $topic->id,
            'key' => $key,
            'label' => "Voltage {$key}",
            'json_path' => "voltages.{$key}",
            'type' => ParameterDataType::Decimal,
            'category' => ParameterCategory::Measurement,
            'sequence' => $sequence + 1,
            'required' => true,
            'is_active' => true,
            'validation_rules' => ['min' => 0, 'max' => 480],
            'mutation_expression' => null,
            'validation_error_code' => null,
        ]);
    }

    ParameterDefinition::factory()->create([
        'schema_version_topic_id' => $topic->id,
        'key' => 'total_energy_kwh',
        'label' => 'Total Energy',
        'json_path' => 'energy.total_energy_kwh',
        'type' => ParameterDataType::Decimal,
        'category' => ParameterCategory::Counter,
        'unit' => 'kWh',
        'sequence' => 10,
        'required' => true,
        'is_active' => true,
        'validation_rules' => ['category' => 'counter', 'min' => 0],
        'mutation_expression' => null,
        'validation_error_code' => null,
    ]);

    foreach (['A1', 'A2', 'A3'] as $sequence => $key) {
        ParameterDefinition::factory()->create([
            'schema_version_topic_id' => $topic->id,
            'key' => $key,
            'label' => "Current {$key}",
            'json_path' => "currents.{$key}",
            'type' => ParameterDataType::Decimal,
            'category' => ParameterCategory::Measurement,
            'unit' => 'A',
            'sequence' => $sequence + 11,
            'required' => true,
            'is_active' => true,
            'validation_rules' => ['min' => 0, 'max' => 150],
            'mutation_expression' => null,
            'validation_error_code' => null,
        ]);
    }

    ParameterDefinition::factory()->create([
        'schema_version_topic_id' => $topic->id,
        'key' => 'status',
        'label' => 'Status',
        'json_path' => '$.status',
        'type' => ParameterDataType::Integer,
        'category' => ParameterCategory::State,
        'sequence' => 14,
        'required' => false,
        'is_active' => true,
        'validation_rules' => ['min' => 0, 'max' => 1],
        'control_ui' => [
            'state_mappings' => [
                ['value' => 0, 'label' => 'OFF', 'color' => '#ef4444'],
                ['value' => 1, 'label' => 'ON', 'color' => '#22c55e'],
            ],
        ],
        'mutation_expression' => null,
        'validation_error_code' => null,
    ]);

    $dashboard = IoTDashboard::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Energy Meter Polling',
        'slug' => 'energy-meter-polling',
    ]);
    $device = Device::factory()->create([
        'organization_id' => $organization->id,
        'device_type_id' => $deviceType->id,
        'device_schema_version_id' => $schemaVersion->id,
        'connection_state' => 'online',
        'last_seen_at' => now()->subMinute(),
    ]);

    return [$organization, $dashboard, $topic, $device];
}

function createLineWidgetSnapshotContext(): array
{
    [$organization, $dashboard, $topic, $device] = createDashboardSnapshotBaseContext();

    $widget = IoTDashboardWidget::factory()->create([
        'iot_dashboard_id' => $dashboard->id,
        'device_id' => $device->id,
        'schema_version_topic_id' => $topic->id,
        'type' => 'line_chart',
        'title' => 'Voltages',
        'config' => [
            'series' => [
                ['key' => 'V1', 'label' => 'Voltage V1', 'color' => '#22d3ee'],
                ['key' => 'V2', 'label' => 'Voltage V2', 'color' => '#a855f7'],
                ['key' => 'V3', 'label' => 'Voltage V3', 'color' => '#f97316'],
            ],
            'transport' => [
                'use_websocket' => true,
                'use_polling' => true,
                'polling_interval_seconds' => 10,
            ],
            'window' => [
                'lookback_minutes' => 240,
                'max_points' => 240,
            ],
        ],
    ]);

    return [$organization, $dashboard, $topic, $widget, $device];
}

function createBarWidgetSnapshotContext(string $interval = 'hourly'): array
{
    [$organization, $dashboard, $topic, $device] = createDashboardSnapshotBaseContext();

    $widget = IoTDashboardWidget::factory()->create([
        'iot_dashboard_id' => $dashboard->id,
        'device_id' => $device->id,
        'schema_version_topic_id' => $topic->id,
        'type' => 'bar_chart',
        'title' => 'Energy Consumption',
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
                'lookback_minutes' => $interval === 'daily' ? 43200 : 1440,
                'max_points' => $interval === 'daily' ? 31 : 24,
            ],
            'bar_interval' => $interval,
        ],
    ]);

    return [$organization, $dashboard, $topic, $widget, $device];
}

function createGaugeWidgetSnapshotContext(): array
{
    [$organization, $dashboard, $topic, $device] = createDashboardSnapshotBaseContext();

    $widget = IoTDashboardWidget::factory()->create([
        'iot_dashboard_id' => $dashboard->id,
        'device_id' => $device->id,
        'schema_version_topic_id' => $topic->id,
        'type' => 'gauge_chart',
        'title' => 'Phase A Gauge',
        'config' => [
            'series' => [
                ['key' => 'A1', 'label' => 'Phase A', 'color' => '#38bdf8'],
            ],
            'transport' => [
                'use_websocket' => true,
                'use_polling' => true,
                'polling_interval_seconds' => 10,
            ],
            'window' => [
                'lookback_minutes' => 120,
                'max_points' => 1,
            ],
            'gauge_style' => 'classic',
            'gauge_min' => 0,
            'gauge_max' => 100,
            'gauge_ranges' => [
                ['from' => 0, 'to' => 60, 'color' => '#22c55e'],
                ['from' => 60, 'to' => 80, 'color' => '#f59e0b'],
                ['from' => 80, 'to' => 100, 'color' => '#ef4444'],
            ],
        ],
    ]);

    return [$organization, $dashboard, $topic, $widget, $device];
}

function buildStatusSummaryWidgetConfig(array $rows, array $transport = [], array $window = []): array
{
    return [
        'rows' => $rows,
        'transport' => [
            'use_websocket' => $transport['use_websocket'] ?? true,
            'use_polling' => $transport['use_polling'] ?? true,
            'polling_interval_seconds' => $transport['polling_interval_seconds'] ?? 10,
        ],
        'window' => [
            'lookback_minutes' => $window['lookback_minutes'] ?? 180,
            'max_points' => $window['max_points'] ?? 1,
        ],
    ];
}

function createStatusSummaryWidgetSnapshotContext(?array $config = null): array
{
    [$organization, $dashboard, $topic, $device] = createDashboardSnapshotBaseContext();

    $widget = IoTDashboardWidget::factory()->statusSummary()->create([
        'iot_dashboard_id' => $dashboard->id,
        'device_id' => $device->id,
        'schema_version_topic_id' => $topic->id,
        'title' => 'Latest Readings',
        'config' => $config ?? buildStatusSummaryWidgetConfig([
            ['tiles' => [[
                'key' => 'total_energy_kwh',
                'label' => 'Total kWh',
                'base_color' => '#0ea5e9',
                'unit' => 'kWh',
                'threshold_ranges' => [],
                'source' => [
                    'type' => 'latest_parameter',
                    'parameter_key' => 'total_energy_kwh',
                ],
            ]]],
            ['tiles' => [
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
                    'key' => 'V2',
                    'label' => 'V2',
                    'base_color' => '#3b82f6',
                    'unit' => 'Volts',
                    'threshold_ranges' => [],
                    'source' => [
                        'type' => 'latest_parameter',
                        'parameter_key' => 'V2',
                    ],
                ],
                [
                    'key' => 'V3',
                    'label' => 'V3',
                    'base_color' => '#8b5cf6',
                    'unit' => 'Volts',
                    'threshold_ranges' => [],
                    'source' => [
                        'type' => 'latest_parameter',
                        'parameter_key' => 'V3',
                    ],
                ],
            ]],
            ['tiles' => [
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
                [
                    'key' => 'A2',
                    'label' => 'A2',
                    'base_color' => '#14b8a6',
                    'unit' => 'A',
                    'threshold_ranges' => [],
                    'source' => [
                        'type' => 'latest_parameter',
                        'parameter_key' => 'A2',
                    ],
                ],
                [
                    'key' => 'A3',
                    'label' => 'A3',
                    'base_color' => '#f59e0b',
                    'unit' => 'A',
                    'threshold_ranges' => [],
                    'source' => [
                        'type' => 'latest_parameter',
                        'parameter_key' => 'A3',
                    ],
                ],
            ]],
        ]),
    ]);

    return [$organization, $dashboard, $topic, $widget, $device];
}

function createStateCardWidgetSnapshotContext(): array
{
    [$organization, $dashboard, $topic, $device] = createDashboardSnapshotBaseContext();

    $widget = IoTDashboardWidget::factory()->stateCard()->create([
        'iot_dashboard_id' => $dashboard->id,
        'device_id' => $device->id,
        'schema_version_topic_id' => $topic->id,
        'title' => 'Door State',
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
            'display_style' => 'pill',
            'state_mappings' => [
                ['value' => '0', 'label' => 'OPEN', 'color' => '#ef4444'],
                ['value' => '1', 'label' => 'CLOSED', 'color' => '#22c55e'],
            ],
        ],
    ]);

    return [$organization, $dashboard, $topic, $widget, $device];
}

function createStateTimelineWidgetSnapshotContext(): array
{
    [$organization, $dashboard, $topic, $device] = createDashboardSnapshotBaseContext();

    $widget = IoTDashboardWidget::factory()->stateTimeline()->create([
        'iot_dashboard_id' => $dashboard->id,
        'device_id' => $device->id,
        'schema_version_topic_id' => $topic->id,
        'title' => 'Door History',
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
                ['value' => '0', 'label' => 'OPEN', 'color' => '#ef4444'],
                ['value' => '1', 'label' => 'CLOSED', 'color' => '#22c55e'],
            ],
        ],
    ]);

    return [$organization, $dashboard, $topic, $widget, $device];
}

it('returns configured line series points for snapshots', function (): void {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($admin);

    [, $dashboard, $topic, $widget, $device] = createLineWidgetSnapshotContext();
    $otherDevice = Device::factory()->create([
        'organization_id' => $device->organization_id,
        'device_type_id' => $device->device_type_id,
        'device_schema_version_id' => $device->device_schema_version_id,
    ]);

    $baseTime = now()->subMinutes(10);

    DeviceTelemetryLog::factory()->forDevice($device)->forTopic($topic)->create([
        'recorded_at' => $baseTime->copy(),
        'transformed_values' => ['V1' => 228.5, 'V2' => 229.1, 'V3' => 230.4],
        'raw_payload' => ['voltages' => ['V1' => 228.5, 'V2' => 229.1, 'V3' => 230.4]],
        'validation_status' => ValidationStatus::Valid,
    ]);
    DeviceTelemetryLog::factory()->forDevice($device)->forTopic($topic)->create([
        'recorded_at' => $baseTime->copy()->addMinutes(5),
        'transformed_values' => ['V1' => 229.2, 'V2' => 230.0, 'V3' => 231.6],
        'raw_payload' => ['voltages' => ['V1' => 229.2, 'V2' => 230.0, 'V3' => 231.6]],
        'validation_status' => ValidationStatus::Valid,
    ]);
    DeviceTelemetryLog::factory()->forDevice($device)->forTopic($topic)->create([
        'recorded_at' => $baseTime->copy()->addMinutes(10),
        'transformed_values' => ['V1' => 230.7, 'V2' => 231.2, 'V3' => 232.8],
        'raw_payload' => ['voltages' => ['V1' => 230.7, 'V2' => 231.2, 'V3' => 232.8]],
        'validation_status' => ValidationStatus::Valid,
    ]);
    DeviceTelemetryLog::factory()->forDevice($otherDevice)->forTopic($topic)->create([
        'recorded_at' => $baseTime->copy()->addMinutes(10),
        'transformed_values' => ['V1' => 999.0, 'V2' => 999.0, 'V3' => 999.0],
        'raw_payload' => ['voltages' => ['V1' => 999.0, 'V2' => 999.0, 'V3' => 999.0]],
        'validation_status' => ValidationStatus::Valid,
    ]);

    $response = $this->getJson(route('admin.iot-dashboard.dashboards.snapshots', [
        'dashboard' => $dashboard,
        'widget' => $widget->id,
    ]));

    $response->assertOk()
        ->assertJsonPath('version', '2.0')
        ->assertJsonPath('dashboard_id', $dashboard->id);

    $widgetSnapshot = $response->json('widgets.0');
    $seriesByKey = collect($widgetSnapshot['series'] ?? [])->keyBy('key');

    expect($widgetSnapshot['id'] ?? null)->toBe($widget->id)
        ->and($widgetSnapshot['type'] ?? null)->toBe('line_chart')
        ->and($seriesByKey->keys()->all())->toBe(['V1', 'V2', 'V3'])
        ->and($seriesByKey['V1']['points'])->toHaveCount(3)
        ->and($seriesByKey['V2']['points'])->toHaveCount(3)
        ->and($seriesByKey['V3']['points'])->toHaveCount(3)
        ->and($seriesByKey['V1']['points'][2]['value'])->toBe(230.7)
        ->and($seriesByKey['V2']['points'][2]['value'])->toBe(231.2)
        ->and($seriesByKey['V3']['points'][2]['value'])->toBe(232.8)
        ->and($seriesByKey['V1']['points'][2]['value'])->not->toBe(999.0)
        ->and($seriesByKey['V2']['points'][2]['value'])->not->toBe(999.0)
        ->and($seriesByKey['V3']['points'][2]['value'])->not->toBe(999.0);
});

it('filters line chart snapshots to an absolute history range', function (): void {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($admin);

    [, $dashboard, $topic, $widget, $device] = createLineWidgetSnapshotContext();

    $baseTime = now()->subHours(2);

    DeviceTelemetryLog::factory()->forDevice($device)->forTopic($topic)->create([
        'recorded_at' => $baseTime->copy(),
        'transformed_values' => ['V1' => 226.1, 'V2' => 227.4, 'V3' => 228.2],
        'validation_status' => ValidationStatus::Valid,
    ]);
    DeviceTelemetryLog::factory()->forDevice($device)->forTopic($topic)->create([
        'recorded_at' => $baseTime->copy()->addHour(),
        'transformed_values' => ['V1' => 229.5, 'V2' => 230.1, 'V3' => 231.7],
        'validation_status' => ValidationStatus::Valid,
    ]);
    DeviceTelemetryLog::factory()->forDevice($device)->forTopic($topic)->create([
        'recorded_at' => $baseTime->copy()->addHour()->addMinutes(45),
        'transformed_values' => ['V1' => 232.2, 'V2' => 233.4, 'V3' => 234.1],
        'validation_status' => ValidationStatus::Valid,
    ]);

    $response = $this->getJson(route('admin.iot-dashboard.dashboards.snapshots', [
        'dashboard' => $dashboard,
        'widget' => $widget->id,
        'history_from_at' => $baseTime->copy()->addMinutes(30)->toIso8601String(),
        'history_until_at' => $baseTime->copy()->addHours(2)->toIso8601String(),
    ]));

    $response->assertOk();

    $points = collect(data_get($response->json('widgets.0'), 'series.0.points', []));

    expect($points)->toHaveCount(2)
        ->and($points->pluck('value')->all())->toBe([229.5, 232.2]);
});

it('forbids snapshots endpoint access for users outside the dashboard organization', function (): void {
    [, $dashboard, , $widget] = createLineWidgetSnapshotContext();

    $user = User::factory()->create(['is_super_admin' => false]);
    $this->actingAs($user);

    $this->getJson(route('admin.iot-dashboard.dashboards.snapshots', [
        'dashboard' => $dashboard,
        'widget' => $widget->id,
    ]))->assertForbidden();
});

it('allows snapshots endpoint access for organization members', function (): void {
    [$organization, $dashboard, , $widget] = createLineWidgetSnapshotContext();

    $user = User::factory()->create(['is_super_admin' => false]);
    $user->organizations()->attach($organization->id);
    $this->actingAs($user);

    $this->getJson(route('admin.iot-dashboard.dashboards.snapshots', [
        'dashboard' => $dashboard,
        'widget' => $widget->id,
    ]))->assertOk();
});

it('returns only requested widgets when snapshots are fetched in a batch', function (): void {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($admin);

    [, $dashboard, $topic, $lineWidget, $device] = createLineWidgetSnapshotContext();

    $gaugeWidget = IoTDashboardWidget::factory()->create([
        'iot_dashboard_id' => $dashboard->id,
        'device_id' => $device->id,
        'schema_version_topic_id' => $topic->id,
        'type' => 'gauge_chart',
        'title' => 'Phase A Gauge',
        'config' => [
            'series' => [
                ['key' => 'A1', 'label' => 'Phase A', 'color' => '#38bdf8'],
            ],
            'transport' => [
                'use_websocket' => true,
                'use_polling' => true,
                'polling_interval_seconds' => 10,
            ],
            'window' => [
                'lookback_minutes' => 120,
                'max_points' => 1,
            ],
            'gauge_style' => 'classic',
            'gauge_min' => 0,
            'gauge_max' => 100,
            'gauge_ranges' => [
                ['from' => 0, 'to' => 60, 'color' => '#22c55e'],
                ['from' => 60, 'to' => 80, 'color' => '#f59e0b'],
                ['from' => 80, 'to' => 100, 'color' => '#ef4444'],
            ],
        ],
    ]);

    $response = $this->getJson(route('admin.iot-dashboard.dashboards.snapshots', [
        'dashboard' => $dashboard,
        'widgets' => [$gaugeWidget->id],
    ]));

    $response->assertOk();

    expect(collect($response->json('widgets'))->pluck('id')->all())->toBe([$gaugeWidget->id])
        ->and(collect($response->json('widgets'))->pluck('id')->all())->not->toContain($lineWidget->id);
});

it('returns hourly consumption buckets for bar chart widgets', function (): void {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($admin);

    [, $dashboard, $topic, $widget, $device] = createBarWidgetSnapshotContext('hourly');

    $baseTime = now()->subHours(3)->startOfHour();

    DeviceTelemetryLog::factory()->forDevice($device)->forTopic($topic)->create([
        'recorded_at' => $baseTime->copy(),
        'transformed_values' => ['total_energy_kwh' => 100.0],
        'raw_payload' => ['energy' => ['total_energy_kwh' => 100.0]],
        'validation_status' => ValidationStatus::Valid,
    ]);
    DeviceTelemetryLog::factory()->forDevice($device)->forTopic($topic)->create([
        'recorded_at' => $baseTime->copy()->addMinutes(30),
        'transformed_values' => ['total_energy_kwh' => 101.0],
        'raw_payload' => ['energy' => ['total_energy_kwh' => 101.0]],
        'validation_status' => ValidationStatus::Valid,
    ]);
    DeviceTelemetryLog::factory()->forDevice($device)->forTopic($topic)->create([
        'recorded_at' => $baseTime->copy()->addHour(),
        'transformed_values' => ['total_energy_kwh' => 101.2],
        'raw_payload' => ['energy' => ['total_energy_kwh' => 101.2]],
        'validation_status' => ValidationStatus::Valid,
    ]);
    DeviceTelemetryLog::factory()->forDevice($device)->forTopic($topic)->create([
        'recorded_at' => $baseTime->copy()->addHour()->addMinutes(30),
        'transformed_values' => ['total_energy_kwh' => 102.6],
        'raw_payload' => ['energy' => ['total_energy_kwh' => 102.6]],
        'validation_status' => ValidationStatus::Valid,
    ]);
    DeviceTelemetryLog::factory()->forDevice($device)->forTopic($topic)->create([
        'recorded_at' => $baseTime->copy()->addHours(2),
        'transformed_values' => ['total_energy_kwh' => 103.1],
        'raw_payload' => ['energy' => ['total_energy_kwh' => 103.1]],
        'validation_status' => ValidationStatus::Valid,
    ]);
    DeviceTelemetryLog::factory()->forDevice($device)->forTopic($topic)->create([
        'recorded_at' => $baseTime->copy()->addHours(2)->addMinutes(30),
        'transformed_values' => ['total_energy_kwh' => 104.0],
        'raw_payload' => ['energy' => ['total_energy_kwh' => 104.0]],
        'validation_status' => ValidationStatus::Valid,
    ]);

    $response = $this->getJson(route('admin.iot-dashboard.dashboards.snapshots', [
        'dashboard' => $dashboard,
        'widget' => $widget->id,
    ]));

    $response->assertOk();

    $widgetSnapshot = $response->json('widgets.0');
    $points = collect(data_get($widgetSnapshot, 'series.0.points'));
    $values = $points->pluck('value')->map(fn (mixed $value): float => (float) $value)->all();

    expect($widgetSnapshot['interval'] ?? null)->toBe('hourly')
        ->and($points)->toHaveCount(3)
        ->and($values)->toBe([1.0, 1.4, 0.9]);
});

it('filters bar chart snapshots to an absolute history range', function (): void {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($admin);

    [, $dashboard, $topic, $widget, $device] = createBarWidgetSnapshotContext('hourly');

    $baseTime = now()->subHours(3)->startOfHour();

    DeviceTelemetryLog::factory()->forDevice($device)->forTopic($topic)->create([
        'recorded_at' => $baseTime->copy(),
        'transformed_values' => ['total_energy_kwh' => 100.0],
        'validation_status' => ValidationStatus::Valid,
    ]);
    DeviceTelemetryLog::factory()->forDevice($device)->forTopic($topic)->create([
        'recorded_at' => $baseTime->copy()->addMinutes(30),
        'transformed_values' => ['total_energy_kwh' => 101.0],
        'validation_status' => ValidationStatus::Valid,
    ]);
    DeviceTelemetryLog::factory()->forDevice($device)->forTopic($topic)->create([
        'recorded_at' => $baseTime->copy()->addHour(),
        'transformed_values' => ['total_energy_kwh' => 101.2],
        'validation_status' => ValidationStatus::Valid,
    ]);
    DeviceTelemetryLog::factory()->forDevice($device)->forTopic($topic)->create([
        'recorded_at' => $baseTime->copy()->addHour()->addMinutes(30),
        'transformed_values' => ['total_energy_kwh' => 102.6],
        'validation_status' => ValidationStatus::Valid,
    ]);
    DeviceTelemetryLog::factory()->forDevice($device)->forTopic($topic)->create([
        'recorded_at' => $baseTime->copy()->addHours(2),
        'transformed_values' => ['total_energy_kwh' => 103.1],
        'validation_status' => ValidationStatus::Valid,
    ]);
    DeviceTelemetryLog::factory()->forDevice($device)->forTopic($topic)->create([
        'recorded_at' => $baseTime->copy()->addHours(2)->addMinutes(30),
        'transformed_values' => ['total_energy_kwh' => 104.0],
        'validation_status' => ValidationStatus::Valid,
    ]);

    $response = $this->getJson(route('admin.iot-dashboard.dashboards.snapshots', [
        'dashboard' => $dashboard,
        'widget' => $widget->id,
        'history_from_at' => $baseTime->copy()->addMinutes(45)->toIso8601String(),
        'history_until_at' => $baseTime->copy()->addHours(3)->toIso8601String(),
    ]));

    $response->assertOk();

    $values = collect(data_get($response->json('widgets.0'), 'series.0.points', []))
        ->pluck('value')
        ->map(fn (mixed $value): float => (float) $value)
        ->all();

    expect($values)->toBe([1.4, 0.9]);
});

it('returns daily consumption buckets for bar chart widgets', function (): void {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($admin);

    [, $dashboard, $topic, $widget, $device] = createBarWidgetSnapshotContext('daily');

    $dayOne = now()->subDays(2)->startOfDay();
    $dayTwo = now()->subDay()->startOfDay();

    DeviceTelemetryLog::factory()->forDevice($device)->forTopic($topic)->create([
        'recorded_at' => $dayOne->copy(),
        'transformed_values' => ['total_energy_kwh' => 200.0],
        'raw_payload' => ['energy' => ['total_energy_kwh' => 200.0]],
        'validation_status' => ValidationStatus::Valid,
    ]);
    DeviceTelemetryLog::factory()->forDevice($device)->forTopic($topic)->create([
        'recorded_at' => $dayOne->copy()->addHours(12),
        'transformed_values' => ['total_energy_kwh' => 205.0],
        'raw_payload' => ['energy' => ['total_energy_kwh' => 205.0]],
        'validation_status' => ValidationStatus::Valid,
    ]);
    DeviceTelemetryLog::factory()->forDevice($device)->forTopic($topic)->create([
        'recorded_at' => $dayOne->copy()->addHours(23)->addMinutes(45),
        'transformed_values' => ['total_energy_kwh' => 210.0],
        'raw_payload' => ['energy' => ['total_energy_kwh' => 210.0]],
        'validation_status' => ValidationStatus::Valid,
    ]);
    DeviceTelemetryLog::factory()->forDevice($device)->forTopic($topic)->create([
        'recorded_at' => $dayTwo->copy(),
        'transformed_values' => ['total_energy_kwh' => 210.5],
        'raw_payload' => ['energy' => ['total_energy_kwh' => 210.5]],
        'validation_status' => ValidationStatus::Valid,
    ]);
    DeviceTelemetryLog::factory()->forDevice($device)->forTopic($topic)->create([
        'recorded_at' => $dayTwo->copy()->addHours(23)->addMinutes(45),
        'transformed_values' => ['total_energy_kwh' => 216.2],
        'raw_payload' => ['energy' => ['total_energy_kwh' => 216.2]],
        'validation_status' => ValidationStatus::Valid,
    ]);

    $response = $this->getJson(route('admin.iot-dashboard.dashboards.snapshots', [
        'dashboard' => $dashboard,
        'widget' => $widget->id,
    ]));

    $response->assertOk();

    $widgetSnapshot = $response->json('widgets.0');
    $points = collect(data_get($widgetSnapshot, 'series.0.points'));
    $values = $points->pluck('value')->map(fn (mixed $value): float => (float) $value)->all();

    expect($widgetSnapshot['interval'] ?? null)->toBe('daily')
        ->and($points)->toHaveCount(2)
        ->and($values)->toBe([10.0, 5.7]);
});

it('returns latest value point for gauge chart widgets based on max points', function (): void {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($admin);

    [, $dashboard, $topic, $widget, $device] = createGaugeWidgetSnapshotContext();

    $baseTime = now()->subMinutes(10);

    DeviceTelemetryLog::factory()->forDevice($device)->forTopic($topic)->create([
        'recorded_at' => $baseTime->copy(),
        'transformed_values' => ['A1' => 12.4],
        'raw_payload' => ['currents' => ['A1' => 12.4]],
        'validation_status' => ValidationStatus::Valid,
    ]);
    DeviceTelemetryLog::factory()->forDevice($device)->forTopic($topic)->create([
        'recorded_at' => $baseTime->copy()->addMinutes(5),
        'transformed_values' => ['A1' => 14.8],
        'raw_payload' => ['currents' => ['A1' => 14.8]],
        'validation_status' => ValidationStatus::Valid,
    ]);

    $response = $this->getJson(route('admin.iot-dashboard.dashboards.snapshots', [
        'dashboard' => $dashboard,
        'widget' => $widget->id,
    ]));

    $response->assertOk();

    $widgetSnapshot = $response->json('widgets.0');

    $response->assertJsonPath('widgets.0.id', $widget->id)
        ->assertJsonPath('widgets.0.series.0.key', 'A1')
        ->assertJsonPath('widgets.0.series.0.points.0.value', 14.8);

    expect(data_get($widgetSnapshot, 'series.0.points'))->toHaveCount(1);
});

it('returns the latest seven numeric values for status summary widgets', function (): void {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($admin);

    [, $dashboard, $topic, $widget, $device] = createStatusSummaryWidgetSnapshotContext();

    $baseTime = now()->subMinutes(12);

    DeviceTelemetryLog::factory()->forDevice($device)->forTopic($topic)->create([
        'recorded_at' => $baseTime->copy(),
        'transformed_values' => [
            'V1' => 228.4,
            'V2' => 229.1,
            'V3' => 227.9,
            'A1' => 9.7,
            'A2' => 9.5,
            'A3' => 9.1,
            'total_energy_kwh' => 441.2,
        ],
        'validation_status' => ValidationStatus::Valid,
    ]);
    DeviceTelemetryLog::factory()->forDevice($device)->forTopic($topic)->create([
        'recorded_at' => $baseTime->copy()->addMinutes(7),
        'transformed_values' => [
            'V1' => 230.1,
            'V2' => 230.7,
            'V3' => 229.8,
            'A1' => 10.2,
            'A2' => 10.0,
            'A3' => 9.8,
            'total_energy_kwh' => 444.6,
        ],
        'validation_status' => ValidationStatus::Valid,
    ]);

    $response = $this->getJson(route('admin.iot-dashboard.dashboards.snapshots', [
        'dashboard' => $dashboard,
        'widget' => $widget->id,
    ]));

    $response->assertOk()
        ->assertJsonPath('widgets.0.type', 'status_summary')
        ->assertJsonPath('widgets.0.device_connection_state', 'online')
        ->assertJsonPath('widgets.0.series.0.points.0.value', 444.6)
        ->assertJsonPath('widgets.0.series.1.points.0.value', 230.1)
        ->assertJsonPath('widgets.0.series.4.points.0.value', 10.2);

    expect(collect(data_get($response->json('widgets.0'), 'series'))->pluck('key')->all())->toBe([
        'total_energy_kwh',
        'V1',
        'V2',
        'V3',
        'A1',
        'A2',
        'A3',
    ]);
});

it('applies threshold colors to status summary tiles based on resolved values', function (): void {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($admin);

    [, $dashboard, $topic, $widget, $device] = createStatusSummaryWidgetSnapshotContext(
        buildStatusSummaryWidgetConfig([
            ['tiles' => [[
                'key' => 'V1',
                'label' => 'V1',
                'base_color' => '#0ea5e9',
                'unit' => 'Volts',
                'threshold_ranges' => [
                    ['from' => null, 'to' => 229, 'color' => '#ef4444'],
                    ['from' => 229, 'to' => null, 'color' => '#22c55e'],
                ],
                'source' => [
                    'type' => 'latest_parameter',
                    'parameter_key' => 'V1',
                ],
            ]]],
        ]),
    );

    DeviceTelemetryLog::factory()->forDevice($device)->forTopic($topic)->create([
        'recorded_at' => now()->subMinute(),
        'transformed_values' => ['V1' => 230.1],
        'validation_status' => ValidationStatus::Valid,
    ]);

    $response = $this->getJson(route('admin.iot-dashboard.dashboards.snapshots', [
        'dashboard' => $dashboard,
        'widget' => $widget->id,
    ]));

    $response->assertOk()
        ->assertJsonPath('widgets.0.series.0.color', '#22c55e');
});

it('ignores history ranges for non-history widgets', function (): void {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($admin);

    [, $dashboard, $topic, $widget, $device] = createGaugeWidgetSnapshotContext();

    $baseTime = now()->subMinutes(10);

    DeviceTelemetryLog::factory()->forDevice($device)->forTopic($topic)->create([
        'recorded_at' => $baseTime->copy(),
        'transformed_values' => ['A1' => 12.4],
        'validation_status' => ValidationStatus::Valid,
    ]);
    DeviceTelemetryLog::factory()->forDevice($device)->forTopic($topic)->create([
        'recorded_at' => $baseTime->copy()->addMinutes(5),
        'transformed_values' => ['A1' => 14.8],
        'validation_status' => ValidationStatus::Valid,
    ]);

    $response = $this->getJson(route('admin.iot-dashboard.dashboards.snapshots', [
        'dashboard' => $dashboard,
        'widget' => $widget->id,
        'history_from_at' => now()->addHour()->toIso8601String(),
        'history_until_at' => now()->addHours(2)->toIso8601String(),
    ]));

    $response->assertOk()
        ->assertJsonPath('widgets.0.series.0.points.0.value', 14.8);
});

it('rejects invalid absolute history ranges', function (): void {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($admin);

    [, $dashboard, , $widget] = createLineWidgetSnapshotContext();

    $this->getJson(route('admin.iot-dashboard.dashboards.snapshots', [
        'dashboard' => $dashboard,
        'widget' => $widget->id,
        'history_from_at' => now()->subHour()->toIso8601String(),
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['history_until_at']);

    $this->getJson(route('admin.iot-dashboard.dashboards.snapshots', [
        'dashboard' => $dashboard,
        'widget' => $widget->id,
        'history_from_at' => now()->toIso8601String(),
        'history_until_at' => now()->subHour()->toIso8601String(),
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['history_until_at']);
});

it('returns the latest mapped state for state card widgets', function (): void {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($admin);

    [, $dashboard, $topic, $widget, $device] = createStateCardWidgetSnapshotContext();

    DeviceTelemetryLog::factory()->forDevice($device)->forTopic($topic)->create([
        'recorded_at' => now()->subMinutes(10),
        'transformed_values' => ['status' => 0],
        'raw_payload' => ['status' => 0],
        'validation_status' => ValidationStatus::Valid,
    ]);
    DeviceTelemetryLog::factory()->forDevice($device)->forTopic($topic)->create([
        'recorded_at' => now()->subMinutes(2),
        'transformed_values' => ['status' => 1],
        'raw_payload' => ['status' => 1],
        'validation_status' => ValidationStatus::Valid,
    ]);

    $response = $this->getJson(route('admin.iot-dashboard.dashboards.snapshots', [
        'dashboard' => $dashboard,
        'widget' => $widget->id,
    ]));

    $response->assertOk()
        ->assertJsonPath('widgets.0.type', 'state_card')
        ->assertJsonPath('widgets.0.device_connection_state', 'online')
        ->assertJsonPath('widgets.0.series.0.points.0.value', 2)
        ->assertJsonPath('widgets.0.series.0.points.0.state_label', 'CLOSED');
});

it('returns mapped discrete state points for state timeline widgets', function (): void {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($admin);

    [, $dashboard, $topic, $widget, $device] = createStateTimelineWidgetSnapshotContext();

    DeviceTelemetryLog::factory()->forDevice($device)->forTopic($topic)->create([
        'recorded_at' => now()->subMinutes(15),
        'transformed_values' => ['status' => 1],
        'raw_payload' => ['status' => 1],
        'validation_status' => ValidationStatus::Valid,
    ]);
    DeviceTelemetryLog::factory()->forDevice($device)->forTopic($topic)->create([
        'recorded_at' => now()->subMinutes(10),
        'transformed_values' => ['status' => 0],
        'raw_payload' => ['status' => 0],
        'validation_status' => ValidationStatus::Valid,
    ]);
    DeviceTelemetryLog::factory()->forDevice($device)->forTopic($topic)->create([
        'recorded_at' => now()->subMinutes(5),
        'transformed_values' => ['status' => 1],
        'raw_payload' => ['status' => 1],
        'validation_status' => ValidationStatus::Valid,
    ]);

    $response = $this->getJson(route('admin.iot-dashboard.dashboards.snapshots', [
        'dashboard' => $dashboard,
        'widget' => $widget->id,
    ]));

    $response->assertOk()
        ->assertJsonPath('widgets.0.type', 'state_timeline')
        ->assertJsonPath('widgets.0.series.0.points.0.value', 2)
        ->assertJsonPath('widgets.0.series.0.points.0.state_label', 'CLOSED')
        ->assertJsonPath('widgets.0.series.0.points.1.value', 1)
        ->assertJsonPath('widgets.0.series.0.points.1.state_label', 'OPEN')
        ->assertJsonPath('widgets.0.series.0.points.2.value', 2)
        ->assertJsonPath('widgets.0.series.0.points.2.state_label', 'CLOSED');
});

it('filters state timeline snapshots to an absolute history range', function (): void {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($admin);

    [, $dashboard, $topic, $widget, $device] = createStateTimelineWidgetSnapshotContext();

    $baseTime = now()->subMinutes(20);

    DeviceTelemetryLog::factory()->forDevice($device)->forTopic($topic)->create([
        'recorded_at' => $baseTime->copy(),
        'transformed_values' => ['status' => 1],
        'raw_payload' => ['status' => 1],
        'validation_status' => ValidationStatus::Valid,
    ]);
    DeviceTelemetryLog::factory()->forDevice($device)->forTopic($topic)->create([
        'recorded_at' => $baseTime->copy()->addMinutes(8),
        'transformed_values' => ['status' => 0],
        'raw_payload' => ['status' => 0],
        'validation_status' => ValidationStatus::Valid,
    ]);
    DeviceTelemetryLog::factory()->forDevice($device)->forTopic($topic)->create([
        'recorded_at' => $baseTime->copy()->addMinutes(16),
        'transformed_values' => ['status' => 1],
        'raw_payload' => ['status' => 1],
        'validation_status' => ValidationStatus::Valid,
    ]);

    $response = $this->getJson(route('admin.iot-dashboard.dashboards.snapshots', [
        'dashboard' => $dashboard,
        'widget' => $widget->id,
        'history_from_at' => $baseTime->copy()->addMinutes(5)->toIso8601String(),
        'history_until_at' => $baseTime->copy()->addMinutes(18)->toIso8601String(),
    ]));

    $response->assertOk()
        ->assertJsonPath('widgets.0.series.0.points.0.state_label', 'OPEN')
        ->assertJsonPath('widgets.0.series.0.points.1.state_label', 'CLOSED');

    expect(data_get($response->json('widgets.0'), 'series.0.points'))->toHaveCount(2);
});
