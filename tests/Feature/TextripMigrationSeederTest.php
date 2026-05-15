<?php

declare(strict_types=1);

use App\Domain\DataIngestion\DTO\IncomingTelemetryEnvelope;
use App\Domain\DataIngestion\Jobs\ProcessInboundTelemetryJob;
use App\Domain\DataIngestion\Models\DeviceSignalBinding;
use App\Domain\DataIngestion\Services\DeviceSignalBindingResolver;
use App\Domain\DataIngestion\Services\TelemetryIngestionService;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceSchema\Models\ParameterDefinition;
use App\Domain\Shared\Models\Organization;
use App\Events\TelemetryReceived;
use Database\Seeders\TextripMigrationSeeder;
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

it('seeds textrip hubs, vendor-native child ids, and schema variants from the recovered inventory', function (): void {
    $this->seed(TextripMigrationSeeder::class);

    $organization = Organization::query()
        ->where('slug', TextripMigrationSeeder::ORGANIZATION_SLUG)
        ->first();

    expect($organization)->not->toBeNull();

    $hubExternalIds = Device::query()
        ->where('organization_id', $organization?->id)
        ->whereNull('parent_device_id')
        ->orderBy('external_id')
        ->pluck('external_id')
        ->all();

    $childDevices = Device::query()
        ->with(['deviceType', 'schemaVersion.schema'])
        ->where('organization_id', $organization?->id)
        ->whereNotNull('parent_device_id')
        ->orderBy('external_id')
        ->get();

    $schemaCounts = $childDevices
        ->groupBy(fn (Device $device): string => (string) $device->schemaVersion?->schema?->name.'@v'.(string) $device->schemaVersion?->version)
        ->map(static fn ($devices): int => $devices->count())
        ->all();

    $specialEnergyDevice = $childDevices->firstWhere('external_id', '869244041759394-27');
    $standardModbusDevice = $childDevices->firstWhere('external_id', '869604063872807-51');
    $dieselTankDevice = $childDevices->firstWhere('external_id', '869604063866064-51');

    expect($hubExternalIds)->toBe([
        '869244041759394',
        '869604063839871',
        '869604063842719',
        '869604063866064',
        '869604063867138',
        '869604063867195',
        '869604063872807',
        '869604063874100',
    ])->and($childDevices)->toHaveCount(18)
        ->and($childDevices->where('deviceType.key', 'energy_meter'))->toHaveCount(11)
        ->and($childDevices->where('deviceType.key', 'tank_level_sensor'))->toHaveCount(7)
        ->and($schemaCounts)->toMatchArray([
            'Energy Meter Contract@v2' => 10,
            'Energy Meter Contract@v3' => 1,
            'Tank Level Sensor Contract@v1' => 5,
            'Tank Level Sensor Contract@v2' => 1,
            'Tank Level Sensor Contract@v3' => 1,
        ])
        ->and($specialEnergyDevice?->schemaVersion?->schema?->name)->toBe('Energy Meter Contract')
        ->and($specialEnergyDevice?->schemaVersion?->version)->toBe(3)
        ->and($specialEnergyDevice?->metadata['schema_variant'] ?? null)->toBe('ac_voltage_alias')
        ->and($standardModbusDevice?->schemaVersion?->schema?->name)->toBe('Tank Level Sensor Contract')
        ->and($standardModbusDevice?->schemaVersion?->version)->toBe(1)
        ->and($standardModbusDevice?->metadata['schema_variant'] ?? null)->toBe('modbus_standard')
        ->and($dieselTankDevice?->schemaVersion?->version)->toBe(2)
        ->and($dieselTankDevice?->metadata['schema_variant'] ?? null)->toBe('modbus_diesel_3000');

    /** @var ParameterDefinition|null $phaseAVoltage */
    $phaseAVoltage = $specialEnergyDevice?->schemaVersion?->topics()->where('key', 'telemetry')->first()?->parameters()->where('key', 'PhaseAVoltage')->first();
    /** @var ParameterDefinition|null $tankLevel */
    $tankLevel = $dieselTankDevice?->schemaVersion?->topics()->where('key', 'telemetry')->first()?->parameters()->where('key', 'ioid1')->first();

    $energyBinding = DeviceSignalBinding::query()
        ->where('device_id', $specialEnergyDevice?->id)
        ->where('source_json_path', '$.object_values.PhaseAVoltage.value')
        ->first();
    $tankBinding = DeviceSignalBinding::query()
        ->where('device_id', $dieselTankDevice?->id)
        ->first();

    expect($phaseAVoltage?->getAttribute('mutation_expression'))->toMatchArray([
        '/' => [
            ['var' => 'val'],
            10,
        ],
    ])->and($tankLevel?->getAttribute('mutation_expression'))->toMatchArray([
        '+' => [
            ['*' => [0.00000000107, ['*' => array_fill(0, 6, ['var' => 'val'])]]],
            ['*' => [-0.0000005068, ['*' => array_fill(0, 5, ['var' => 'val'])]]],
            ['*' => [0.00009239, ['*' => array_fill(0, 4, ['var' => 'val'])]]],
            ['*' => [-0.009619, ['*' => array_fill(0, 3, ['var' => 'val'])]]],
            ['*' => [0.6458, ['*' => array_fill(0, 2, ['var' => 'val'])]]],
            ['*' => [6.154, ['var' => 'val']]],
            -1.723,
        ],
    ])->and($energyBinding?->source_topic)->toBe('migration/source/imoni/869244041759394/27/telemetry')
        ->and($energyBinding?->source_json_path)->toBe('$.object_values.PhaseAVoltage.value')
        ->and($tankBinding?->source_topic)->toBe('migration/source/imoni/869604063866064/51/telemetry')
        ->and($tankBinding?->source_json_path)->toBe('$.io_1_value');
});

it('expands textrip AC source telemetry into one logical device payload using mixed numeric and named object bindings', function (): void {
    $this->seed(TextripMigrationSeeder::class);

    /** @var DeviceSignalBindingResolver $resolver */
    $resolver = app(DeviceSignalBindingResolver::class);

    $sourceTopic = 'migration/source/imoni/869244041759394/21/telemetry';

    $expandedEnvelopes = $resolver->expand(new IncomingTelemetryEnvelope(
        sourceSubject: str_replace('/', '.', $sourceTopic),
        mqttTopic: $sourceTopic,
        payload: [
            'peripheral_name' => 'AC_energyMate1',
            'peripheral_type_hex' => '21',
            'io_1_value' => 2300,
            'io_2_value' => 2310,
            'io_3_value' => 2290,
            'io_4_value' => 1210,
            'io_5_value' => 1180,
            'io_6_value' => 1090,
            'io_7_value' => 450000,
            'object_values' => [
                'TotalEnergy' => ['value' => 450.0],
                'PhaseAVoltage' => ['value' => 230.4],
                'PhaseBVoltage' => ['value' => 231.1],
                'PhaseCVoltage' => ['value' => 229.8],
                'TotalActivePower' => ['value' => 1820.4],
                'TotalReactivePower' => ['value' => 820.1],
                'totalPowerFactor' => ['value' => 0.97],
            ],
        ],
        receivedAt: now(),
    ));

    $envelope = $expandedEnvelopes->sole();

    expect($resolver->supportsTopic($sourceTopic))->toBeTrue()
        ->and($envelope->deviceExternalId)->toBe('869244041759394-21')
        ->and($envelope->mqttTopic)->toBe('energy/869244041759394-21/telemetry')
        ->and($envelope->payload)->toMatchArray([
            'v1' => 2300.0,
            'v2' => 2310.0,
            'v3' => 2290.0,
            'e1' => 450000.0,
            'TotalEnergy' => 450.0,
            'PhaseAVoltage' => 230.4,
            'TotalActivePower' => 1820.4,
            '_meta' => [
                'binding_mode' => 'device_signal',
                'source_adapter' => 'imoni',
                'source_topic' => $sourceTopic,
                'source_subject' => str_replace('/', '.', $sourceTopic),
            ],
        ]);
});

it('ingests textrip AC telemetry with the recovered voltage-alias calibration variant', function (): void {
    Event::fake([TelemetryReceived::class]);

    $this->seed(TextripMigrationSeeder::class);

    $device = Device::query()->where('external_id', '869244041759394-27')->firstOrFail();
    $sourceTopic = 'migration/source/imoni/869244041759394/27/telemetry';

    $job = new ProcessInboundTelemetryJob((new IncomingTelemetryEnvelope(
        sourceSubject: str_replace('/', '.', $sourceTopic),
        mqttTopic: $sourceTopic,
        payload: [
            'peripheral_name' => 'AC_energyMate7',
            'peripheral_type_hex' => '27',
            'io_1_value' => 2300,
            'io_2_value' => 2310,
            'io_3_value' => 2290,
            'io_4_value' => 1210,
            'io_5_value' => 1180,
            'io_6_value' => 1090,
            'io_7_value' => 450000,
            'object_values' => [
                'TotalEnergy' => ['value' => 450.0],
                'PhaseAVoltage' => ['value' => 2304.0],
                'PhaseBVoltage' => ['value' => 2311.0],
                'PhaseCVoltage' => ['value' => 2298.0],
                'TotalActivePower' => ['value' => 1820.4],
                'TotalReactivePower' => ['value' => 820.1],
                'totalPowerFactor' => ['value' => 0.97],
            ],
            '_meta' => [
                'hub_imei' => '869244041759394',
                'source_key' => '869244041759394:27',
            ],
        ],
        receivedAt: now(),
    ))->toArray());

    $job->handle(app(TelemetryIngestionService::class), app(DeviceSignalBindingResolver::class));

    $device->refresh();
    $telemetryLog = $device->telemetryLogs()->latest('id')->first();

    expect($device->connection_state)->toBe('online')
        ->and($telemetryLog?->transformed_values)->toMatchArray([
            'v1' => 230.0,
            'v2' => 231.0,
            'v3' => 229.0,
            'e1' => 450.0,
            'TotalEnergy' => 450.0,
            'PhaseAVoltage' => 230.4,
            'PhaseBVoltage' => 231.1,
            'PhaseCVoltage' => 229.8,
            'TotalActivePower' => 1820.4,
        ])
        ->and($telemetryLog?->mutated_values)->toMatchArray([
            'PhaseAVoltage' => 230.4,
            'PhaseBVoltage' => 231.1,
            'PhaseCVoltage' => 229.8,
        ]);

    Event::assertDispatched(TelemetryReceived::class, 1);
});

it('ingests textrip modbus telemetry with the recovered polynomial calibration variant', function (): void {
    Event::fake([TelemetryReceived::class]);

    $this->seed(TextripMigrationSeeder::class);

    $device = Device::query()->where('external_id', '869604063866064-51')->firstOrFail();
    $sourceTopic = 'migration/source/imoni/869604063866064/51/telemetry';

    $job = new ProcessInboundTelemetryJob((new IncomingTelemetryEnvelope(
        sourceSubject: str_replace('/', '.', $sourceTopic),
        mqttTopic: $sourceTopic,
        payload: [
            'peripheral_name' => 'Modbus1',
            'peripheral_type_hex' => '51',
            'io_1_value' => 100,
            '_meta' => [
                'hub_imei' => '869604063866064',
                'source_key' => '869604063866064:51',
            ],
        ],
        receivedAt: now(),
    ))->toArray());

    $job->handle(app(TelemetryIngestionService::class), app(DeviceSignalBindingResolver::class));

    $device->refresh();
    $telemetryLog = $device->telemetryLogs()->latest('id')->first();
    $expectedLevel = 0.00000000107 * (100 ** 6)
        - 0.0000005068 * (100 ** 5)
        + 0.00009239 * (100 ** 4)
        - 0.009619 * (100 ** 3)
        + 0.6458 * (100 ** 2)
        + 6.154 * 100
        - 1.723;

    expect($device->connection_state)->toBe('online')
        ->and($telemetryLog?->mutated_values['ioid1'] ?? null)->toEqualWithDelta($expectedLevel, 0.0001)
        ->and($telemetryLog?->transformed_values['ioid1'] ?? null)->toEqualWithDelta($expectedLevel, 0.0001);

    Event::assertDispatched(TelemetryReceived::class, 1);
});
