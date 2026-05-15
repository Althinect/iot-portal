<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Models\DeviceType;
use App\Domain\DeviceManagement\Models\VirtualDeviceLink;
use App\Domain\DeviceSchema\Enums\ParameterCategory;
use App\Domain\DeviceSchema\Enums\ParameterDataType;
use App\Domain\DeviceSchema\Models\DeviceSchema;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Domain\DeviceSchema\Models\ParameterDefinition;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Domain\IoTDashboard\Enums\WidgetType;
use App\Domain\IoTDashboard\Models\IoTDashboard;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use App\Domain\IoTDashboard\Widgets\StenterUtilization\StenterUtilizationConfig;
use App\Domain\IoTDashboard\Widgets\StenterUtilization\StenterUtilizationSnapshotResolver;
use App\Domain\Shared\Models\Organization;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use App\Filament\Admin\Pages\IoTDashboardSupport\WidgetFormOptionsService;
use App\Support\DeviceSelectOptions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('calculates stenter status efficiency and length counters from physical source devices', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-15 02:30:00', 'UTC'));

    [$widget, $config, $statusTopic, $lengthTopic, $statusDevice, $lengthDevice] = createStenterUtilizationSnapshotContext();

    foreach ([
        ['2026-04-15 00:00:00', 0],
        ['2026-04-15 01:30:00', 1],
        ['2026-04-15 02:00:00', 0],
    ] as [$recordedAt, $status]) {
        DeviceTelemetryLog::factory()
            ->forDevice($statusDevice)
            ->forTopic($statusTopic)
            ->create([
                'recorded_at' => CarbonImmutable::parse($recordedAt, 'UTC'),
                'received_at' => CarbonImmutable::parse($recordedAt, 'UTC'),
                'transformed_values' => ['status' => $status],
            ]);
    }

    foreach ([
        ['2026-04-01 00:00:00', 80],
        ['2026-04-14 16:30:00', 50],
        ['2026-04-15 00:20:00', 95],
        ['2026-04-15 00:30:00', 100],
        ['2026-04-15 02:30:00', 145],
    ] as [$recordedAt, $length]) {
        DeviceTelemetryLog::factory()
            ->forDevice($lengthDevice)
            ->forTopic($lengthTopic)
            ->create([
                'recorded_at' => CarbonImmutable::parse($recordedAt, 'UTC'),
                'received_at' => CarbonImmutable::parse($recordedAt, 'UTC'),
                'transformed_values' => ['length' => $length],
            ]);
    }

    $snapshot = app(StenterUtilizationSnapshotResolver::class)->resolve($widget, $config);

    expect(data_get($snapshot, 'card.state.label'))->toBe('Running with Fabric')
        ->and(data_get($snapshot, 'card.state.is_running'))->toBeTrue()
        ->and(data_get($snapshot, 'card.current_shift.label'))->toBe('Shift 1')
        ->and(data_get($snapshot, 'card.current_shift.efficiency_percent'))->toBe(75.0)
        ->and(data_get($snapshot, 'card.current_shift.run_minutes'))->toBe(90.0)
        ->and(data_get($snapshot, 'card.current_shift.idle_minutes'))->toBe(30.0)
        ->and(data_get($snapshot, 'card.percentage_thresholds'))->toBe(StenterUtilizationConfig::defaultPercentageThresholds())
        ->and(data_get($snapshot, 'card.length_counters.month.value'))->toBe(65.0)
        ->and(data_get($snapshot, 'card.length_counters.current_shift.value'))->toBe(45.0)
        ->and(data_get($snapshot, 'card.length_counters.previous_shift.value'))->toBe(50.0)
        ->and(data_get($snapshot, 'card.status_segments'))->toHaveCount(2)
        ->and(data_get($snapshot, 'card.status_segments.0.state'))->toBe('off')
        ->and(data_get($snapshot, 'card.status_segments.1.state'))->toBe('on');

    CarbonImmutable::setTestNow();
});

it('normalizes UTC shift configuration with safe defaults', function (): void {
    $config = StenterUtilizationConfig::fromArray([
        'shifts' => [
            ['label' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00'],
            ['label' => '', 'start_time' => '14:00', 'end_time' => '22:00'],
            ['label' => 'Invalid', 'start_time' => '25:00', 'end_time' => '06:00'],
        ],
    ]);

    expect($config->shifts())->toBe([
        ['label' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00'],
        ['label' => 'Shift 2', 'start_time' => '14:00', 'end_time' => '22:00'],
    ])
        ->and($config->useWebsocket())->toBeFalse()
        ->and($config->pollingIntervalSeconds())->toBe(30);
});

it('normalizes percentage thresholds with red amber and green defaults', function (): void {
    $config = StenterUtilizationConfig::fromArray([
        'percentage_thresholds' => [
            ['label' => 'Low', 'minimum' => '0', 'maximum' => '49.5', 'color' => '#FF0000'],
            ['label' => 'Invalid', 'minimum' => '90', 'maximum' => '80', 'color' => '#000000'],
            ['label' => '', 'minimum' => '50', 'maximum' => '100', 'color' => '#00AA00'],
        ],
    ]);

    expect($config->percentageThresholds())->toBe([
        ['label' => 'Low', 'minimum' => 0.0, 'maximum' => 49.5, 'color' => '#ff0000'],
        ['label' => 'Threshold 3', 'minimum' => 50.0, 'maximum' => 100.0, 'color' => '#00aa00'],
    ])
        ->and(StenterUtilizationConfig::fromArray([])->percentageThresholds())->toBe(StenterUtilizationConfig::defaultPercentageThresholds());
});

it('lists only fully linked virtual stenters and resolves physical status and length sources', function (): void {
    [$widget, , $statusTopic, $lengthTopic, $statusDevice, $lengthDevice] = createStenterUtilizationSnapshotContext();
    $dashboard = IoTDashboard::query()->findOrFail($widget->iot_dashboard_id);
    $virtualDevice = Device::query()->findOrFail($widget->device_id);

    VirtualDeviceLink::factory()->create([
        'virtual_device_id' => $virtualDevice->id,
        'source_device_id' => $statusDevice->id,
        'purpose' => 'status',
    ]);
    VirtualDeviceLink::factory()->create([
        'virtual_device_id' => $virtualDevice->id,
        'source_device_id' => $lengthDevice->id,
        'purpose' => 'length',
    ]);

    $statusOnlyVirtualDevice = Device::factory()->create([
        'organization_id' => $virtualDevice->organization_id,
        'device_type_id' => $virtualDevice->device_type_id,
        'device_schema_version_id' => $virtualDevice->device_schema_version_id,
        'is_virtual' => true,
    ]);
    VirtualDeviceLink::factory()->create([
        'virtual_device_id' => $statusOnlyVirtualDevice->id,
        'source_device_id' => $statusDevice->id,
        'purpose' => 'status',
    ]);

    $service = app(WidgetFormOptionsService::class);
    $options = $service->stenterDeviceOptions($dashboard);
    $resolvedInput = $service->resolveInput($dashboard, [
        'widget_type' => WidgetType::StenterUtilization->value,
        'device_id' => $virtualDevice->id,
    ]);

    expect($options)->toHaveKey((string) $virtualDevice->id)
        ->and($options)->not->toHaveKey((string) $statusOnlyVirtualDevice->id)
        ->and(data_get($resolvedInput, 'stenter_sources.status'))->toBe([
            'device_id' => $statusDevice->id,
            'schema_version_topic_id' => $statusTopic->id,
            'parameter_key' => 'status',
        ])
        ->and(data_get($resolvedInput, 'stenter_sources.length'))->toBe([
            'device_id' => $lengthDevice->id,
            'schema_version_topic_id' => $lengthTopic->id,
            'parameter_key' => 'length',
        ]);
});

it('includes tj india stenter device types in the stenter dropdown', function (): void {
    [$widget, , , , $statusDevice, $lengthDevice] = createStenterUtilizationSnapshotContext();
    $dashboard = IoTDashboard::query()->findOrFail($widget->iot_dashboard_id);

    $tjIndiaType = DeviceType::query()->firstOrCreate([
        'key' => 'stenter_line',
    ], [
        'name' => 'Stenter Line',
        'default_protocol' => 'http',
        'protocol_config' => [],
    ]);

    $tjIndiaVirtualDevice = Device::factory()->create([
        'organization_id' => $dashboard->organization_id,
        'device_type_id' => $tjIndiaType->id,
        'device_schema_version_id' => $widget->device->device_schema_version_id,
        'is_virtual' => true,
        'name' => 'TJ India Stenter',
    ]);

    VirtualDeviceLink::factory()->create([
        'virtual_device_id' => $tjIndiaVirtualDevice->id,
        'source_device_id' => $statusDevice->id,
        'purpose' => 'status',
    ]);
    VirtualDeviceLink::factory()->create([
        'virtual_device_id' => $tjIndiaVirtualDevice->id,
        'source_device_id' => $lengthDevice->id,
        'purpose' => 'length',
    ]);

    $options = app(WidgetFormOptionsService::class)->stenterDeviceOptions($dashboard);

    expect(DeviceSelectOptions::findLabel($options, $tjIndiaVirtualDevice->id))->toContain('TJ India Stenter');
});

function createStenterUtilizationSnapshotContext(): array
{
    $organization = Organization::factory()->create();
    $stenterType = DeviceType::query()->firstOrCreate([
        'key' => 'stenter_line',
    ], [
        'name' => 'Stenter Line',
        'default_protocol' => 'http',
        'protocol_config' => [],
    ]);
    $statusType = DeviceType::factory()->mqtt()->create(['key' => 'status_source']);
    $lengthType = DeviceType::factory()->mqtt()->create(['key' => 'length_source']);

    [$stenterSchemaVersion, $stenterTopic] = createStenterSchemaVersion($stenterType, []);
    [$statusSchemaVersion, $statusTopic] = createStenterSchemaVersion($statusType, [
        ['key' => 'status', 'label' => 'Status', 'type' => ParameterDataType::Integer, 'category' => ParameterCategory::State],
    ]);
    [$lengthSchemaVersion, $lengthTopic] = createStenterSchemaVersion($lengthType, [
        ['key' => 'length', 'label' => 'Length', 'type' => ParameterDataType::Decimal, 'category' => ParameterCategory::Counter],
    ]);

    $virtualDevice = Device::factory()->create([
        'organization_id' => $organization->id,
        'device_type_id' => $stenterType->id,
        'device_schema_version_id' => $stenterSchemaVersion->id,
        'is_virtual' => true,
        'connection_state' => 'online',
        'last_seen_at' => CarbonImmutable::now('UTC'),
    ]);
    $statusDevice = Device::factory()->create([
        'organization_id' => $organization->id,
        'device_type_id' => $statusType->id,
        'device_schema_version_id' => $statusSchemaVersion->id,
    ]);
    $lengthDevice = Device::factory()->create([
        'organization_id' => $organization->id,
        'device_type_id' => $lengthType->id,
        'device_schema_version_id' => $lengthSchemaVersion->id,
    ]);

    $dashboard = IoTDashboard::factory()->create([
        'organization_id' => $organization->id,
    ]);
    $config = StenterUtilizationConfig::fromArray([
        'sources' => [
            'status' => [
                'device_id' => $statusDevice->id,
                'schema_version_topic_id' => $statusTopic->id,
                'parameter_key' => 'status',
            ],
            'length' => [
                'device_id' => $lengthDevice->id,
                'schema_version_topic_id' => $lengthTopic->id,
                'parameter_key' => 'length',
            ],
        ],
        'shifts' => StenterUtilizationConfig::defaultShifts(),
    ]);
    $widget = IoTDashboardWidget::factory()->create([
        'iot_dashboard_id' => $dashboard->id,
        'device_id' => $virtualDevice->id,
        'schema_version_topic_id' => $stenterTopic->id,
        'type' => WidgetType::StenterUtilization->value,
        'config' => $config->toArray(),
    ]);

    return [$widget, $config, $statusTopic, $lengthTopic, $statusDevice, $lengthDevice];
}

/**
 * @param  array<int, array{key: string, label: string, type: ParameterDataType, category: ParameterCategory}>  $parameters
 * @return array{0: DeviceSchemaVersion, 1: SchemaVersionTopic}
 */
function createStenterSchemaVersion(DeviceType $deviceType, array $parameters): array
{
    $schema = DeviceSchema::factory()->forDeviceType($deviceType)->create();
    $schemaVersion = DeviceSchemaVersion::factory()->active()->create([
        'device_schema_id' => $schema->id,
    ]);
    $topic = SchemaVersionTopic::factory()->publish()->create([
        'device_schema_version_id' => $schemaVersion->id,
        'key' => 'telemetry',
        'suffix' => 'telemetry',
        'label' => 'Telemetry',
    ]);

    foreach ($parameters as $sequence => $parameter) {
        ParameterDefinition::factory()->create([
            'schema_version_topic_id' => $topic->id,
            'key' => $parameter['key'],
            'label' => $parameter['label'],
            'json_path' => '$.'.$parameter['key'],
            'type' => $parameter['type'],
            'category' => $parameter['category'],
            'unit' => $parameter['key'] === 'length' ? 'm' : null,
            'sequence' => $sequence + 1,
            'required' => true,
            'is_active' => true,
            'mutation_expression' => null,
            'validation_error_code' => null,
        ]);
    }

    return [$schemaVersion, $topic];
}
