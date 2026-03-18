<?php

declare(strict_types=1);

use App\Domain\DataIngestion\DTO\IncomingTelemetryEnvelope;
use App\Domain\DataIngestion\Jobs\ProcessInboundTelemetryJob;
use App\Domain\DataIngestion\Models\DeviceSignalBinding;
use App\Domain\DataIngestion\Services\DeviceSignalBindingResolver;
use App\Domain\DataIngestion\Services\TelemetryIngestionService;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Models\DeviceType;
use App\Domain\DeviceManagement\Services\DevicePresenceMessageHandler;
use App\Domain\DeviceSchema\Enums\ParameterCategory;
use App\Domain\DeviceSchema\Enums\ParameterDataType;
use App\Domain\DeviceSchema\Models\DeviceSchema;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Domain\Shared\Models\Organization;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use App\Events\TelemetryReceived;
use Database\Seeders\WitcoMigrationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'broadcasting.default' => 'null',
        'cache.default' => 'array',
        'iot.presence.write_throttle_seconds' => 0,
    ]);
});

it('seeds witco hubs with mapped peripheral child devices only', function (): void {
    $this->seed(WitcoMigrationSeeder::class);

    $organization = Organization::query()
        ->where('slug', WitcoMigrationSeeder::ORGANIZATION_SLUG)
        ->first();

    expect($organization)->not->toBeNull();

    $hubExternalIds = Device::query()
        ->where('organization_id', $organization?->id)
        ->whereNull('parent_device_id')
        ->orderBy('external_id')
        ->pluck('external_id')
        ->all();

    expect($hubExternalIds)->toBe([
        '869244041754866',
        '869244041759279',
        '869244041759568',
        '869244041767199',
    ]);

    $childExternalIds = Device::query()
        ->where('organization_id', $organization?->id)
        ->whereNotNull('parent_device_id')
        ->orderBy('external_id')
        ->pluck('external_id')
        ->all();

    expect($childExternalIds)->toBe([
        '869244041754866-00-02',
        '869244041754866-00-03',
        '869244041759279-00-01',
        '869244041759279-00-02',
        '869244041759279-00-03',
        '869244041759568-00-01',
        '869244041759568-00-02',
        '869244041767199-00-01',
        '869244041767199-00-02',
    ]);

    $statusDevice = Device::query()
        ->where('organization_id', $organization?->id)
        ->where('external_id', '869244041754866-00-02')
        ->first();

    $statusTopic = $statusDevice?->schemaVersion?->topics()->where('key', 'telemetry')->first();
    $statusParameter = $statusTopic?->parameters()->where('key', 'status')->first();
    $statusBinding = DeviceSignalBinding::query()
        ->where('device_id', $statusDevice?->id)
        ->first();

    expect($statusTopic)->not->toBeNull()
        ->and($statusDevice?->deviceType?->organization_id)->toBeNull()
        ->and($statusDevice?->deviceType?->key)->toBe('imoni_status')
        ->and($statusDevice?->schemaVersion?->schema?->name)->toBe('IMONI Status')
        ->and($statusTopic?->resolvedTopic($statusDevice))->toBe('devices/imoni-status/869244041754866-00-02/telemetry')
        ->and($statusParameter)->not->toBeNull()
        ->and($statusParameter?->type)->toBe(ParameterDataType::Integer)
        ->and($statusParameter?->category)->toBe(ParameterCategory::State)
        ->and($statusParameter?->resolvedValidationRules())->toMatchArray([
            'min' => 0,
            'max' => 1,
        ])
        ->and($statusParameter?->resolvedStateMappings())->toBe([
            ['value' => '0', 'label' => 'OFF', 'color' => '#ef4444'],
            ['value' => '1', 'label' => 'ON', 'color' => '#22c55e'],
        ])
        ->and($statusParameter?->getAttribute('mutation_expression'))->toMatchArray([
            'if' => [
                [
                    '===' => [
                        ['var' => 'val'],
                        1,
                    ],
                ],
                0,
                1,
            ],
        ])
        ->and($statusTopic?->parameters()->orderBy('sequence')->pluck('key')->all())->toBe(['status'])
        ->and($statusBinding)->not->toBeNull()
        ->and($statusBinding?->source_topic)->toBe('migration/source/imoni/869244041754866/00/telemetry')
        ->and($statusBinding?->source_json_path)->toBe('$.io_2_value')
        ->and($statusBinding?->source_adapter)->toBe('imoni')
        ->and(Device::query()->where('organization_id', $organization?->id)->where('external_id', '869244041754866-server')->exists())->toBeFalse()
        ->and(Device::query()->where('organization_id', $organization?->id)->where('external_id', '869244041754866-00')->exists())->toBeFalse();
});

it('marks a witco hub online and ingests source-routed telemetry into physical status devices', function (): void {
    Event::fake([TelemetryReceived::class]);

    $this->seed(WitcoMigrationSeeder::class);

    $hub = Device::query()->where('external_id', '869244041754866')->firstOrFail();
    $waterTankAlarm = Device::query()->where('external_id', '869244041754866-00-02')->firstOrFail();
    $serverRoomInput = Device::query()->where('external_id', '869244041754866-00-03')->firstOrFail();

    /** @var DevicePresenceMessageHandler $presenceHandler */
    $presenceHandler = app(DevicePresenceMessageHandler::class);

    $presenceHandled = $presenceHandler->handle(
        subject: 'devices.869244041754866.presence',
        body: json_encode([
            'status' => 'online',
            '_meta' => [
                'source' => 'node-red-imoni',
            ],
        ], JSON_THROW_ON_ERROR),
        prefix: 'devices',
        suffix: 'presence',
    );

    $hub->refresh();

    expect($presenceHandled)->toBeTrue()
        ->and($hub->connection_state)->toBe('online')
        ->and($hub->last_seen_at)->not->toBeNull();

    $sourceTopic = 'migration/source/imoni/869244041754866/00/telemetry';

    $job = new ProcessInboundTelemetryJob((new IncomingTelemetryEnvelope(
        sourceSubject: str_replace('/', '.', $sourceTopic),
        mqttTopic: $sourceTopic,
        payload: [
            'peripheral_name' => 'iMoni_LITE',
            'peripheral_type_hex' => '00',
            'io_2_value' => 1,
            'io_3_value' => 0,
            '_meta' => [
                'hub_imei' => '869244041754866',
                'source_key' => '869244041754866:00',
            ],
        ],
        receivedAt: now(),
    ))->toArray());

    $job->handle(app(TelemetryIngestionService::class), app(DeviceSignalBindingResolver::class));

    $waterTankAlarm->refresh();
    $serverRoomInput->refresh();

    expect($waterTankAlarm->connection_state)->toBe('online')
        ->and($serverRoomInput->connection_state)->toBe('online')
        ->and($waterTankAlarm->telemetryLogs()->count())->toBe(1)
        ->and($serverRoomInput->telemetryLogs()->count())->toBe(1)
        ->and($waterTankAlarm->telemetryLogs()->first()?->transformed_values)->toMatchArray([
            'status' => 0,
        ])
        ->and($serverRoomInput->telemetryLogs()->first()?->transformed_values)->toMatchArray([
            'status' => 1,
        ]);

    Event::assertDispatched(TelemetryReceived::class, 2);
});

it('removes obsolete witco imoni lite device types, schemas, versions, devices, and logs on reseed', function (): void {
    $organization = Organization::factory()->create([
        'slug' => WitcoMigrationSeeder::ORGANIZATION_SLUG,
        'name' => 'WITCO',
    ]);

    $obsoleteDeviceType = DeviceType::factory()->create([
        'organization_id' => $organization->id,
        'key' => 'witco_imoni_lite',
        'name' => 'WITCO IMoni Lite',
    ]);

    $obsoleteSchema = DeviceSchema::factory()->create([
        'device_type_id' => $obsoleteDeviceType->id,
        'name' => 'WITCO IMoni Lite Contract',
    ]);

    $obsoleteSchemaVersion = DeviceSchemaVersion::factory()->create([
        'device_schema_id' => $obsoleteSchema->id,
        'version' => 1,
        'status' => 'active',
    ]);

    $obsoleteTopic = SchemaVersionTopic::factory()->publish()->create([
        'device_schema_version_id' => $obsoleteSchemaVersion->id,
        'key' => 'telemetry',
        'suffix' => 'telemetry',
    ]);

    $obsoleteDevice = Device::factory()->create([
        'organization_id' => $organization->id,
        'device_type_id' => $obsoleteDeviceType->id,
        'device_schema_version_id' => $obsoleteSchemaVersion->id,
        'external_id' => '869244041754866-server',
    ]);

    DeviceTelemetryLog::factory()
        ->forDevice($obsoleteDevice)
        ->forTopic($obsoleteTopic)
        ->create();

    $obsoleteDevice->delete();

    $this->seed(WitcoMigrationSeeder::class);

    expect(DeviceType::query()->whereKey($obsoleteDeviceType->id)->exists())->toBeFalse()
        ->and(DeviceSchema::withTrashed()->whereKey($obsoleteSchema->id)->exists())->toBeFalse()
        ->and(DeviceSchemaVersion::query()->whereKey($obsoleteSchemaVersion->id)->exists())->toBeFalse()
        ->and(Device::withTrashed()->whereKey($obsoleteDevice->id)->exists())->toBeFalse()
        ->and(DeviceTelemetryLog::query()->where('device_schema_version_id', $obsoleteSchemaVersion->id)->exists())->toBeFalse()
        ->and(DeviceType::query()->where('key', 'legacy_hub')->whereNull('organization_id')->exists())->toBeTrue()
        ->and(DeviceType::query()->where('key', 'imoni_status')->whereNull('organization_id')->exists())->toBeTrue();
});
