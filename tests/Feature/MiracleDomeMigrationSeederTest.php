<?php

declare(strict_types=1);

use App\Domain\DataIngestion\DTO\IncomingTelemetryEnvelope;
use App\Domain\DataIngestion\Jobs\ProcessInboundTelemetryJob;
use App\Domain\DataIngestion\Models\DeviceSignalBinding;
use App\Domain\DataIngestion\Services\DeviceSignalBindingResolver;
use App\Domain\DataIngestion\Services\TelemetryIngestionService;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Services\DevicePresenceMessageHandler;
use App\Domain\DeviceSchema\Models\ParameterDefinition;
use App\Domain\Shared\Models\Organization;
use App\Events\TelemetryReceived;
use Database\Seeders\MiracleDomeMigrationSeeder;
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

it('seeds miracle dome hubs and physical energy meters with normalized source bindings', function (): void {
    $this->seed(MiracleDomeMigrationSeeder::class);

    $organization = Organization::query()
        ->where('slug', MiracleDomeMigrationSeeder::ORGANIZATION_SLUG)
        ->first();

    expect($organization)->not->toBeNull();

    $hubExternalIds = Device::query()
        ->where('organization_id', $organization?->id)
        ->whereNull('parent_device_id')
        ->orderBy('external_id')
        ->pluck('external_id')
        ->all();

    expect($hubExternalIds)->toBe([
        '869244041759261',
        '869244041759402',
    ]);

    $childExternalIds = Device::query()
        ->where('organization_id', $organization?->id)
        ->whereNotNull('parent_device_id')
        ->orderBy('external_id')
        ->pluck('external_id')
        ->all();

    expect($childExternalIds)->toBe([
        'miracle-dome-bts-energy-meter',
        'miracle-dome-server-room-2-energy-meter',
        'miracle-dome-video-room-2-energy-meter',
    ]);

    $serverRoomMeter = Device::query()
        ->where('organization_id', $organization?->id)
        ->where('external_id', 'miracle-dome-server-room-2-energy-meter')
        ->first();

    $telemetryTopic = $serverRoomMeter?->schemaVersion?->topics()->where('key', 'telemetry')->first();
    $parameterKeys = $telemetryTopic?->parameters()->orderBy('sequence')->pluck('key')->all();

    /** @var ParameterDefinition|null $voltageParameter */
    $voltageParameter = $telemetryTopic?->parameters()->where('key', 'V1')->first();
    /** @var ParameterDefinition|null $energyParameter */
    $energyParameter = $telemetryTopic?->parameters()->where('key', 'total_energy_kwh')->first();

    $binding = DeviceSignalBinding::query()
        ->where('device_id', $serverRoomMeter?->id)
        ->where('parameter_definition_id', $energyParameter?->id)
        ->first();

    expect($serverRoomMeter)->not->toBeNull()
        ->and($serverRoomMeter?->deviceType?->key)->toBe('energy_meter')
        ->and($serverRoomMeter?->schemaVersion?->schema?->name)->toBe('Miracle Dome Energy Meter Contract')
        ->and($telemetryTopic?->resolvedTopic($serverRoomMeter))->toBe('energy/miracle-dome-server-room-2-energy-meter/telemetry')
        ->and($parameterKeys)->toBe([
            'V1',
            'V2',
            'V3',
            'A1',
            'A2',
            'A3',
            'total_energy_kwh',
        ])
        ->and($telemetryTopic?->parameters()->where('key', 'power_factor')->exists())->toBeFalse()
        ->and($voltageParameter?->resolvedValidationRules())->toMatchArray([
            'min' => 1800,
            'max' => 2800,
            'category' => 'static',
        ])
        ->and($voltageParameter?->getAttribute('mutation_expression'))->toMatchArray([
            '/' => [
                ['var' => 'val'],
                10,
            ],
        ])
        ->and($energyParameter?->resolvedValidationRules())->toMatchArray([
            'min' => 0,
            'category' => 'counter',
        ])
        ->and($energyParameter?->getAttribute('mutation_expression'))->toMatchArray([
            '/' => [
                ['var' => 'val'],
                1000,
            ],
        ])
        ->and(DeviceSignalBinding::query()->where('device_id', $serverRoomMeter?->id)->count())->toBe(7)
        ->and($binding)->not->toBeNull()
        ->and($binding?->source_topic)->toBe('migration/source/imoni/869244041759402/21/telemetry')
        ->and($binding?->source_json_path)->toBe('$.io_7_value');
});

it('ingests miracle dome raw energy telemetry into one normalized physical device', function (): void {
    Event::fake([TelemetryReceived::class]);

    $this->seed(MiracleDomeMigrationSeeder::class);

    $hub = Device::query()->where('external_id', '869244041759402')->firstOrFail();
    $serverRoomMeter = Device::query()->where('external_id', 'miracle-dome-server-room-2-energy-meter')->firstOrFail();

    /** @var DevicePresenceMessageHandler $presenceHandler */
    $presenceHandler = app(DevicePresenceMessageHandler::class);

    $presenceHandled = $presenceHandler->handle(
        subject: 'devices.869244041759402.presence',
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

    $sourceTopic = 'migration/source/imoni/869244041759402/21/telemetry';

    $job = new ProcessInboundTelemetryJob((new IncomingTelemetryEnvelope(
        sourceSubject: str_replace('/', '.', $sourceTopic),
        mqttTopic: $sourceTopic,
        payload: [
            'peripheral_name' => 'AC_energyMate1',
            'peripheral_type_hex' => '21',
            'io_1_value' => 2300,
            'io_2_value' => 2310,
            'io_3_value' => 2290,
            'io_4_value' => 1200,
            'io_5_value' => 1100,
            'io_6_value' => 1000,
            'io_7_value' => 450000,
            '_meta' => [
                'hub_imei' => '869244041759402',
                'source_key' => '869244041759402:21',
            ],
        ],
        receivedAt: now(),
    ))->toArray());

    $job->handle(app(TelemetryIngestionService::class), app(DeviceSignalBindingResolver::class));

    $serverRoomMeter->refresh();

    $telemetryLog = $serverRoomMeter->telemetryLogs()->latest('id')->first();

    expect($serverRoomMeter->connection_state)->toBe('online')
        ->and($serverRoomMeter->telemetryLogs()->count())->toBe(1)
        ->and($telemetryLog?->transformed_values)->toMatchArray([
            'V1' => 230.0,
            'V2' => 231.0,
            'V3' => 229.0,
            'A1' => 12.0,
            'A2' => 11.0,
            'A3' => 10.0,
            'total_energy_kwh' => 450.0,
        ])
        ->and($telemetryLog?->mutated_values)->toMatchArray([
            'V1' => 230.0,
            'V2' => 231.0,
            'V3' => 229.0,
            'A1' => 12.0,
            'A2' => 11.0,
            'A3' => 10.0,
            'total_energy_kwh' => 450.0,
        ]);

    Event::assertDispatched(TelemetryReceived::class, 1);
});
