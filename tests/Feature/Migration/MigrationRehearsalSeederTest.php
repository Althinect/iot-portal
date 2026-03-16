<?php

declare(strict_types=1);

use App\Domain\DataIngestion\DTO\IncomingTelemetryEnvelope;
use App\Domain\DataIngestion\Enums\IngestionStatus;
use App\Domain\DataIngestion\Services\TelemetryIngestionService;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Services\DevicePresenceMessageHandler;
use App\Domain\Shared\Models\Organization;
use App\Events\TelemetryReceived;
use Database\Seeders\MigrationRehearsalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

const IMONI_HUB_EXTERNAL_ID = '869244049087921';

beforeEach(function (): void {
    config([
        'broadcasting.default' => 'null',
        'cache.default' => 'array',
        'iot.presence.write_throttle_seconds' => 0,
    ]);
});

it('seeds a migration rehearsal hub with mapped child devices and schema topics', function (): void {
    $this->seed(MigrationRehearsalSeeder::class);

    $organization = Organization::query()
        ->where('slug', 'migration-rehearsal')
        ->first();

    expect($organization)->not->toBeNull();

    $hub = Device::query()
        ->where('organization_id', $organization?->id)
        ->where('external_id', IMONI_HUB_EXTERNAL_ID)
        ->first();

    expect($hub)->not->toBeNull()
        ->and($hub?->parent_device_id)->toBeNull();

    $childDevices = $hub?->childDevices()
        ->orderBy('external_id')
        ->get();

    expect($childDevices)->not->toBeNull()
        ->and($childDevices?->pluck('external_id')->all())->toBe([
            IMONI_HUB_EXTERNAL_ID.'-00',
            IMONI_HUB_EXTERNAL_ID.'-11',
            IMONI_HUB_EXTERNAL_ID.'-12',
        ]);

    $ioExt1Device = $childDevices?->firstWhere('external_id', IMONI_HUB_EXTERNAL_ID.'-11');
    $ioExt1Topic = $ioExt1Device?->schemaVersion?->topics()->where('key', 'telemetry')->first();

    expect($ioExt1Topic)->not->toBeNull()
        ->and($ioExt1Topic?->resolvedTopic($ioExt1Device))->toBe('migration/ioext1/'.IMONI_HUB_EXTERNAL_ID.'-11/telemetry');
});

it('marks the seeded hub online from json presence events and ingests normalized child telemetry', function (): void {
    Event::fake([TelemetryReceived::class]);

    $this->seed(MigrationRehearsalSeeder::class);

    $hub = Device::query()->where('external_id', IMONI_HUB_EXTERNAL_ID)->firstOrFail();
    $childDevice = Device::query()->where('external_id', IMONI_HUB_EXTERNAL_ID.'-11')->firstOrFail();

    /** @var DevicePresenceMessageHandler $presenceHandler */
    $presenceHandler = app(DevicePresenceMessageHandler::class);

    $presenceHandled = $presenceHandler->handle(
        subject: 'devices.'.IMONI_HUB_EXTERNAL_ID.'.presence',
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

    $telemetryTopic = $childDevice->schemaVersion()->with('topics')->firstOrFail()
        ->topics
        ->firstWhere('key', 'telemetry');

    expect($telemetryTopic)->not->toBeNull();

    $mqttTopic = $telemetryTopic->resolvedTopic($childDevice);

    /** @var TelemetryIngestionService $ingestionService */
    $ingestionService = app(TelemetryIngestionService::class);

    $message = $ingestionService->ingest(new IncomingTelemetryEnvelope(
        sourceSubject: str_replace('/', '.', $mqttTopic),
        mqttTopic: $mqttTopic,
        payload: [
            'peripheral_name' => 'IOext1',
            'peripheral_type_hex' => '11',
            'io_1_value' => 1,
            'io_1_action' => 'readDigitalIN',
            'io_1_raw_hex' => '022101',
            'io_5_value' => 0,
            'io_5_action' => 'readAnalogIN',
            'io_5_raw_hex' => '01050000',
            '_meta' => [
                'hub_imei' => IMONI_HUB_EXTERNAL_ID,
                'device_external_id' => IMONI_HUB_EXTERNAL_ID.'-11',
                'peripheral_type_hex' => '11',
            ],
        ],
        deviceExternalId: IMONI_HUB_EXTERNAL_ID.'-11',
        receivedAt: now(),
    ));

    $childDevice->refresh();

    expect($message)->not->toBeNull()
        ->and($message?->status)->toBe(IngestionStatus::Completed)
        ->and($childDevice->connection_state)->toBe('online')
        ->and($childDevice->telemetryLogs()->count())->toBe(1)
        ->and($childDevice->telemetryLogs()->first()?->transformed_values)->toMatchArray([
            'peripheral_name' => 'IOext1',
            'peripheral_type_hex' => '11',
            'io_1_value' => 1,
            'io_1_action' => 'readDigitalIN',
            'io_1_raw_hex' => '022101',
            'io_5_value' => 0,
            'io_5_action' => 'readAnalogIN',
            'io_5_raw_hex' => '01050000',
        ]);

    Event::assertDispatched(TelemetryReceived::class, 1);
});
