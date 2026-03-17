<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceSchema\Enums\ParameterDataType;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Domain\DeviceSchema\Models\ParameterDefinition;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Domain\IoTDashboard\Models\IoTDashboard;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use App\Domain\Shared\Services\RuntimeSettingManager;
use App\Domain\Telemetry\Services\TelemetryLogRecorder;
use App\Events\TelemetryReceived;
use App\Filament\Admin\Pages\IoTDashboardSupport\WidgetBootstrapPayloadBuilder;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

it('broadcasts device-topic scoped realtime payload data for dashboard widgets', function (): void {
    Event::fake([TelemetryReceived::class]);

    $schemaVersion = DeviceSchemaVersion::factory()->active()->create();

    $topic = SchemaVersionTopic::factory()->publish()->create([
        'device_schema_version_id' => $schemaVersion->id,
        'key' => 'telemetry',
        'suffix' => 'telemetry',
    ]);

    foreach (['V1', 'V2', 'V3'] as $sequence => $key) {
        ParameterDefinition::factory()->create([
            'schema_version_topic_id' => $topic->id,
            'key' => $key,
            'label' => "Voltage {$key}",
            'json_path' => "voltages.{$key}",
            'type' => ParameterDataType::Decimal,
            'required' => true,
            'is_critical' => false,
            'mutation_expression' => null,
            'validation_error_code' => null,
            'sequence' => $sequence + 1,
            'is_active' => true,
        ]);
    }

    $device = Device::factory()->create([
        'device_schema_version_id' => $schemaVersion->id,
    ]);

    $log = app(TelemetryLogRecorder::class)->record(
        device: $device,
        payload: [
            'voltages' => [
                'V1' => 229.6,
                'V2' => 230.2,
                'V3' => 231.4,
            ],
        ],
        topicSuffix: 'telemetry',
    );

    $event = new TelemetryReceived($log->fresh(['device']));
    $channels = $event->broadcastOn();
    $payload = $event->broadcastWith();

    expect($channels)->toHaveCount(1)
        ->and($channels[0])->toBeInstanceOf(PrivateChannel::class)
        ->and($channels[0]->name)->toBe('private-iot-dashboard.device.'.$device->uuid.'.topic.'.$topic->id)
        ->and($event->connection)->toBe(config('ingestion.side_effects_queue_connection'))
        ->and($event->broadcastQueue())->toBe(config('ingestion.side_effects_queue'))
        ->and($event->broadcastAs())->toBe('telemetry.received')
        ->and($payload['organization_id'] ?? null)->toBe($device->organization_id)
        ->and($payload['device_uuid'] ?? null)->toBe($device->uuid)
        ->and($payload['schema_version_topic_id'] ?? null)->toBe($topic->id)
        ->and($payload['transformed_values']['V1'] ?? null)->toBe(229.6)
        ->and($payload['transformed_values']['V2'] ?? null)->toBe(230.2)
        ->and($payload['transformed_values']['V3'] ?? null)->toBe(231.4)
        ->and(array_key_exists('raw_payload', $payload))->toBeFalse()
        ->and(array_key_exists('validation_errors', $payload))->toBeFalse();
});

it('includes device-topic realtime stream metadata in widget bootstrap payloads', function (): void {
    $schemaVersion = DeviceSchemaVersion::factory()->active()->create();
    $dashboard = IoTDashboard::factory()->create();

    $topic = SchemaVersionTopic::factory()->publish()->create([
        'device_schema_version_id' => $schemaVersion->id,
        'key' => 'telemetry',
        'suffix' => 'telemetry',
    ]);

    $device = Device::factory()->create([
        'organization_id' => $dashboard->organization_id,
        'device_schema_version_id' => $schemaVersion->id,
    ]);

    IoTDashboardWidget::factory()->create([
        'iot_dashboard_id' => $dashboard->id,
        'device_id' => $device->id,
        'schema_version_topic_id' => $topic->id,
    ]);

    $payload = app(WidgetBootstrapPayloadBuilder::class)->build($dashboard->fresh([
        'widgets.topic:id,label,suffix',
        'widgets.device:id,uuid,name,organization_id,external_id',
    ]));

    expect($payload)->toHaveCount(1)
        ->and(data_get($payload, '0.realtime.channel'))
        ->toBe('iot-dashboard.device.'.$device->uuid.'.topic.'.$topic->id)
        ->and(data_get($payload, '0.realtime.sample_window_seconds'))
        ->toBe(2);
});

it('uses a one second realtime sample window for status summary widgets', function (): void {
    $schemaVersion = DeviceSchemaVersion::factory()->active()->create();
    $dashboard = IoTDashboard::factory()->create();

    $topic = SchemaVersionTopic::factory()->publish()->create([
        'device_schema_version_id' => $schemaVersion->id,
        'key' => 'telemetry',
        'suffix' => 'telemetry',
    ]);

    $device = Device::factory()->create([
        'organization_id' => $dashboard->organization_id,
        'device_schema_version_id' => $schemaVersion->id,
    ]);

    IoTDashboardWidget::factory()->statusSummary()->create([
        'iot_dashboard_id' => $dashboard->id,
        'device_id' => $device->id,
        'schema_version_topic_id' => $topic->id,
    ]);

    $payload = app(WidgetBootstrapPayloadBuilder::class)->build($dashboard->fresh([
        'widgets.topic:id,label,suffix',
        'widgets.device:id,uuid,name,organization_id,external_id',
    ]));

    expect($payload)->toHaveCount(1)
        ->and(data_get($payload, '0.type'))->toBe('status_summary')
        ->and(data_get($payload, '0.realtime.sample_window_seconds'))->toBe(1)
        ->and(data_get($payload, '0.series.0.unit'))->toBe('Volts')
        ->and(data_get($payload, '0.series.0.source.parameter_key'))->toBe('V1')
        ->and(data_get($payload, '0.layout_rows.0.tile_keys'))->toBe(['V1', 'A1']);
});

it('disables dashboard realtime broadcast channels when the ingestion realtime kill switch is off', function (): void {
    Event::fake([TelemetryReceived::class]);

    app(RuntimeSettingManager::class)->setGlobalOverrides([
        'ingestion.pipeline.broadcast_realtime' => false,
    ]);

    $schemaVersion = DeviceSchemaVersion::factory()->active()->create();

    $topic = SchemaVersionTopic::factory()->publish()->create([
        'device_schema_version_id' => $schemaVersion->id,
        'key' => 'telemetry',
        'suffix' => 'telemetry',
    ]);

    ParameterDefinition::factory()->create([
        'schema_version_topic_id' => $topic->id,
        'key' => 'V1',
        'label' => 'Voltage V1',
        'json_path' => 'voltages.V1',
        'type' => ParameterDataType::Decimal,
        'required' => true,
        'is_critical' => false,
        'mutation_expression' => null,
        'validation_error_code' => null,
        'sequence' => 1,
        'is_active' => true,
    ]);

    $device = Device::factory()->create([
        'device_schema_version_id' => $schemaVersion->id,
    ]);

    $log = app(TelemetryLogRecorder::class)->record(
        device: $device,
        payload: [
            'voltages' => [
                'V1' => 229.6,
            ],
        ],
        topicSuffix: 'telemetry',
    );

    $event = new TelemetryReceived($log->fresh(['device']));

    expect($event->broadcastOn())->toBe([]);
});
