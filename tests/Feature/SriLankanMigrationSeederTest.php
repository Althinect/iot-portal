<?php

declare(strict_types=1);

use App\Domain\DataIngestion\DTO\IncomingTelemetryEnvelope;
use App\Domain\DataIngestion\Jobs\ProcessInboundTelemetryJob;
use App\Domain\DataIngestion\Models\DeviceSignalBinding;
use App\Domain\DataIngestion\Services\DeviceSignalBindingResolver;
use App\Domain\DataIngestion\Services\TelemetryIngestionService;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Services\DevicePresenceMessageHandler;
use App\Domain\Shared\Models\Organization;
use App\Events\TelemetryReceived;
use Database\Seeders\SriLankanMigrationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'broadcasting.default' => 'null',
        'cache.default' => 'array',
        'iot.presence.write_throttle_seconds' => 0,
    ]);
});

it('seeds the sri lankan organization with the communicating hub hierarchy and legacy source bindings', function (): void {
    seed(SriLankanMigrationSeeder::class);
    seed(SriLankanMigrationSeeder::class);

    $organization = Organization::query()
        ->where('slug', SriLankanMigrationSeeder::ORGANIZATION_SLUG)
        ->first();

    expect($organization)->not->toBeNull();

    $devices = Device::query()
        ->with(['profileVersion.profile', 'parentDevice'])
        ->where('organization_id', $organization?->id)
        ->get();

    $rootDevices = $devices->whereNull('parent_device_id');
    $hubs = $rootDevices->where('profileVersion.profile.key', 'legacy_hub');
    $rootEgravityDevices = $rootDevices->where('profileVersion.profile.key', 'legacy_egravity_sensor');
    $children = $devices->whereNotNull('parent_device_id');
    $offlineAlertCount = $devices
        ->filter(fn (Device $device): bool => (bool) ($device->metadata['legacy_offline_alert_enabled'] ?? false))
        ->count();

    $hub = $devices->firstWhere('name', 'CLD 02 - Hub');
    $climateDevice = $devices->firstWhere('external_id', 'ea2b48f3-911f-4c90-88b7-29ac47799ed7');
    $climateBindings = DeviceSignalBinding::query()
        ->where('device_id', $climateDevice?->id)
        ->orderBy('parameter_key')
        ->get();
    $egravityDevice = $devices->firstWhere('external_id', '00841B48');
    $egravityBindings = DeviceSignalBinding::query()
        ->where('device_id', $egravityDevice?->id)
        ->orderBy('parameter_key')
        ->get();
    $temperatureOnlyEgravityDevice = $devices->firstWhere('external_id', '009C56ED');
    $temperatureOnlyEgravityBindings = DeviceSignalBinding::query()
        ->where('device_id', $temperatureOnlyEgravityDevice?->id)
        ->orderBy('parameter_key')
        ->get();
    $monitoredRoomNames = $devices->pluck('name')->intersect([
        'CLD 02',
        'CLD 03',
        'CLD 04-01',
        'CLD 05-01',
        'CLD 06',
        'CLD 07-02',
        'CLD 08-04',
        'CLD 09-02',
        'CLD 10',
        'CLD 11-01',
    ])->sort()->values()->all();

    expect($hubs)->toHaveCount(10)
        ->and($rootEgravityDevices)->toHaveCount(2)
        ->and($children)->toHaveCount(14)
        ->and($offlineAlertCount)->toBe(11)
        ->and($hub?->external_id)->toBe('869244041754767')
        ->and($hub?->metadata['legacy_virtual_device_id'] ?? null)->toBe('0565b9ec-2912-4ae8-b632-92bc3206f188')
        ->and($children->where('profileVersion.profile.key', 'legacy_climate_sensor'))->toHaveCount(14)
        ->and($rootEgravityDevices->pluck('external_id')->sort()->values()->all())->toBe([
            '00841B48',
            '009C56ED',
        ])
        ->and($climateDevice?->profileVersion?->profile?->key)->toBe('legacy_climate_sensor')
        ->and($climateDevice?->profileVersion?->profile?->name)->toBe('Legacy Climate Sensor')
        ->and($climateDevice?->name)->toBe('CLD 02')
        ->and($climateDevice?->metadata['legacy_device_name'] ?? null)->toBe('ACLD 02')
        ->and($climateBindings)->toHaveCount(2)
        ->and($climateBindings->pluck('source_topic')->unique()->all())->toBe([
            'migration/source/imoni/869244041754767/00/telemetry',
        ])
        ->and($climateBindings->pluck('source_json_path')->all())->toContain(
            '$.io_17_value',
            '$.io_18_value',
        )
        ->and($egravityDevice?->profileVersion?->profile?->key)->toBe('legacy_egravity_sensor')
        ->and($egravityBindings)->toHaveCount(4)
        ->and($egravityBindings->pluck('source_topic')->unique()->all())->toBe([
            'migration/source/egravity/00841B48/telemetry',
        ])
        ->and($egravityDevice?->metadata['legacy_metadata']['manufacturer'] ?? null)->toBe('Egravity')
        ->and($egravityDevice?->name)->toBe('CLD 08-04')
        ->and($egravityDevice?->metadata['legacy_device_name'] ?? null)->toBe('CLD08 - 04')
        ->and($temperatureOnlyEgravityDevice?->profileVersion?->profile?->key)->toBe('legacy_egravity_sensor')
        ->and($temperatureOnlyEgravityDevice?->name)->toBe('CLD 03')
        ->and($temperatureOnlyEgravityDevice?->metadata['legacy_device_name'] ?? null)->toBe('CLD03 - 02')
        ->and($temperatureOnlyEgravityBindings)->toHaveCount(2)
        ->and($temperatureOnlyEgravityBindings->pluck('source_topic')->unique()->all())->toBe([
            'migration/source/egravity/009C56ED/telemetry',
        ])
        ->and($temperatureOnlyEgravityBindings->pluck('source_json_path')->all())->toContain(
            '$.temp_1',
            '$.batt',
        )
        ->and($monitoredRoomNames)->toBe([
            'CLD 02',
            'CLD 03',
            'CLD 04-01',
            'CLD 05-01',
            'CLD 06',
            'CLD 07-02',
            'CLD 08-04',
            'CLD 09-02',
            'CLD 10',
            'CLD 11-01',
        ])
        ->and($devices->pluck('external_id')->intersect(retiredSriLankanExternalIds())->all())->toBe([]);
});

it('removes retired sri lankan migrated devices on rerun without touching manual devices', function (): void {
    $organization = Organization::factory()->create([
        'slug' => SriLankanMigrationSeeder::ORGANIZATION_SLUG,
        'name' => 'SriLankan Airlines Limited',
    ]);

    $retiredHub = Device::factory()->create([
        'organization_id' => $organization->id,
        'external_id' => '869244041754767-copy',
        'name' => 'CLD 02 - Hub (Copy)',
        'metadata' => [
            'migration_origin' => SriLankanMigrationSeeder::ORGANIZATION_SLUG,
        ],
    ]);

    $retiredChild = Device::factory()->create([
        'organization_id' => $organization->id,
        'device_profile_version_id' => $retiredHub->device_profile_version_id,
        'parent_device_id' => $retiredHub->id,
        'external_id' => '13faaec8-a503-4491-b18f-70a3f7436878',
        'name' => 'CLD 03 - 01',
        'metadata' => [
            'migration_origin' => SriLankanMigrationSeeder::ORGANIZATION_SLUG,
        ],
    ]);

    $manualDevice = Device::factory()->create([
        'organization_id' => $organization->id,
        'external_id' => 'manual-sri-lankan-device',
        'metadata' => [],
    ]);

    seed(SriLankanMigrationSeeder::class);

    expect(Device::withTrashed()->whereKey($retiredHub->id)->exists())->toBeFalse()
        ->and(Device::withTrashed()->whereKey($retiredChild->id)->exists())->toBeFalse()
        ->and(Device::query()->whereKey($manualDevice->id)->exists())->toBeTrue();
});

it('marks a sri lankan hub online and ingests hub-level imoni telemetry into multiple child devices', function (): void {
    Event::fake([TelemetryReceived::class]);

    seed(SriLankanMigrationSeeder::class);

    $hub = Device::query()->where('external_id', '869244041759212')->firstOrFail();
    $climateDevice = Device::query()->where('external_id', 'e60ed5a0-8bba-46c4-9ba5-e94aacf4e291')->firstOrFail();
    $temperatureOnlyDevice = Device::query()->where('external_id', '794e0d28-3af5-4524-9b9b-4c61551acaa1')->firstOrFail();

    /** @var DevicePresenceMessageHandler $presenceHandler */
    $presenceHandler = app(DevicePresenceMessageHandler::class);

    $presenceHandled = $presenceHandler->handle(
        subject: 'devices.869244041759212.presence',
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

    $sourceTopic = 'migration/source/imoni/869244041759212/00/telemetry';

    $job = new ProcessInboundTelemetryJob((new IncomingTelemetryEnvelope(
        sourceSubject: str_replace('/', '.', $sourceTopic),
        mqttTopic: $sourceTopic,
        payload: [
            'peripheral_name' => 'iMoni_LITE',
            'peripheral_type_hex' => '00',
            'io_17_value' => 253,
            'io_18_value' => 655,
            'io_21_value' => 32798,
            '_meta' => [
                'hub_imei' => '869244041759212',
                'source_key' => '869244041759212:00',
            ],
        ],
        receivedAt: Carbon::now(),
    ))->toArray());

    $job->handle(app(TelemetryIngestionService::class), app(DeviceSignalBindingResolver::class));

    $climateDevice->refresh();
    $temperatureOnlyDevice->refresh();

    expect($climateDevice->connection_state)->toBe('online')
        ->and($temperatureOnlyDevice->connection_state)->toBe('online')
        ->and($climateDevice->telemetryLogs()->count())->toBe(1)
        ->and($temperatureOnlyDevice->telemetryLogs()->count())->toBe(1)
        ->and($climateDevice->telemetryLogs()->latest('id')->first()?->transformed_values)->toMatchArray([
            'temperature' => 25.3,
            'humidity' => 65.5,
        ])
        ->and($temperatureOnlyDevice->telemetryLogs()->latest('id')->first()?->transformed_values)->toMatchArray([
            'temperature' => -3.0,
        ]);

    Event::assertDispatched(TelemetryReceived::class, 2);
});

it('expands sri lankan egravity telemetry into the retained dashboard device profile', function (): void {
    Event::fake([TelemetryReceived::class]);

    seed(SriLankanMigrationSeeder::class);

    $multiMetricDevice = Device::query()->where('external_id', '00841B48')->firstOrFail();

    $sourceTopic = 'migration/source/egravity/00841B48/telemetry';

    $job = new ProcessInboundTelemetryJob((new IncomingTelemetryEnvelope(
        sourceSubject: str_replace('/', '.', $sourceTopic),
        mqttTopic: $sourceTopic,
        payload: [
            'temp_2' => 4.1,
            'gsm_sl' => -73,
            'batt' => 3.82,
            'pext' => 1,
        ],
        receivedAt: Carbon::now(),
    ))->toArray());

    $job->handle(app(TelemetryIngestionService::class), app(DeviceSignalBindingResolver::class));

    $multiMetricDevice->refresh();

    expect($multiMetricDevice->connection_state)->toBe('online')
        ->and($multiMetricDevice->telemetryLogs()->latest('id')->first()?->transformed_values)->toMatchArray([
            'temperature_2' => 4.1,
            'signal' => -73,
            'battery' => 3.82,
            'external_power' => true,
        ]);

    Event::assertDispatched(TelemetryReceived::class, 1);
});

it('ingests egravity temperature-only telemetry by external_id', function (): void {
    Event::fake([TelemetryReceived::class]);

    seed(SriLankanMigrationSeeder::class);

    $device = Device::query()->where('external_id', '009C56ED')->firstOrFail();

    $sourceTopic = 'migration/source/egravity/009C56ED/telemetry';

    $job = new ProcessInboundTelemetryJob((new IncomingTelemetryEnvelope(
        sourceSubject: str_replace('/', '.', $sourceTopic),
        mqttTopic: $sourceTopic,
        payload: [
            'temp_1' => 4.8,
            'batt' => 3.67,
        ],
        receivedAt: Carbon::now(),
    ))->toArray());

    $job->handle(app(TelemetryIngestionService::class), app(DeviceSignalBindingResolver::class));

    $device->refresh();

    expect($device->connection_state)->toBe('online')
        ->and($device->telemetryLogs()->latest('id')->first()?->transformed_values)->toMatchArray([
            'temperature' => 4.8,
            'battery' => 3.67,
        ]);

    Event::assertDispatched(TelemetryReceived::class, 1);
});

/**
 * @return list<string>
 */
function retiredSriLankanExternalIds(): array
{
    return [
        '869244041760020',
        '869244041754767-copy',
        '169244041754767',
        '169244041773882',
        '13faaec8-a503-4491-b18f-70a3f7436878',
        '7e75ec92-633f-4ab9-bacb-8afaf4253329',
        'a7309973-ec6f-413e-8732-6e3c5052cb46',
        '1772502d-3330-42d9-9f4d-44055e87af10',
        '0ed77c61-9ec1-4da7-a5f1-98d6d309f137',
        '1a744464-b9da-4895-9c7a-02ac2a21632f',
        'cb1e49e8-bdc5-4a1a-8628-2b208beab442',
        '2f07ab92-f7fa-46b5-bc2a-07f3866b81ab',
        '2f07a1db-a93e-4ad3-b9b9-bf02e29f9774',
    ];
}
