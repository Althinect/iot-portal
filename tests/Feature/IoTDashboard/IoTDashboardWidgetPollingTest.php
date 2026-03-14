<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Models\DeviceType;
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
        'unit' => 'kWh',
        'sequence' => 10,
        'required' => true,
        'is_active' => true,
        'validation_rules' => ['category' => 'counter', 'min' => 0],
        'mutation_expression' => null,
        'validation_error_code' => null,
    ]);

    ParameterDefinition::factory()->create([
        'schema_version_topic_id' => $topic->id,
        'key' => 'A1',
        'label' => 'Current A1',
        'json_path' => 'currents.A1',
        'type' => ParameterDataType::Decimal,
        'unit' => 'A',
        'sequence' => 11,
        'required' => true,
        'is_active' => true,
        'validation_rules' => ['min' => 0, 'max' => 150],
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
