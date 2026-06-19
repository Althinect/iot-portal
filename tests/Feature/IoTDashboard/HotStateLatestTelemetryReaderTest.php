<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Models\DeviceType;
use App\Domain\DeviceManagement\Publishing\Nats\NatsDeviceStateStore;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Domain\IoTDashboard\Application\HotStateLatestTelemetryReader;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

class HotStateReaderFakeNatsDeviceStateStore implements NatsDeviceStateStore
{
    /** @var array{topic: string, payload: array<string, mixed>, stored_at: string}|null */
    public ?array $state = null;

    public int $reads = 0;

    /** @var array<string, mixed> */
    public array $lastRead = [];

    public ?Throwable $exception = null;

    public function store(string $deviceUuid, string $topic, array $payload, string $host = '127.0.0.1', int $port = 4223): void {}

    public function getLastState(string $deviceUuid, string $host = '127.0.0.1', int $port = 4223): ?array
    {
        return null;
    }

    public function getAllStates(string $deviceUuid, string $host = '127.0.0.1', int $port = 4223): array
    {
        return [];
    }

    public function getStateByTopic(string $deviceUuid, string $topic, string $host = '127.0.0.1', int $port = 4223): ?array
    {
        $this->reads++;
        $this->lastRead = [
            'device_uuid' => $deviceUuid,
            'topic' => $topic,
            'host' => $host,
            'port' => $port,
        ];

        if ($this->exception instanceof Throwable) {
            throw $this->exception;
        }

        return $this->state;
    }
}

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-17 12:00:00', 'UTC'));

    config([
        'iot_dashboard.hot_state_reads.enabled' => true,
        'ingestion.nats.host' => 'nats.internal',
        'ingestion.nats.port' => 4225,
    ]);

    $stateStore = new HotStateReaderFakeNatsDeviceStateStore;

    app()->instance(HotStateReaderFakeNatsDeviceStateStore::class, $stateStore);
    app()->instance(NatsDeviceStateStore::class, $stateStore);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @return array{device: Device, topic: SchemaVersionTopic}
 */
function createHotStateReaderContext(): array
{
    $schemaVersion = DeviceSchemaVersion::factory()->create();
    $deviceType = DeviceType::factory()->mqtt()->create([
        'protocol_config' => [
            'broker_host' => 'localhost',
            'broker_port' => 1883,
            'username' => null,
            'password' => null,
            'use_tls' => false,
            'base_topic' => 'devices',
        ],
    ]);
    $device = Device::factory()->create([
        'device_type_id' => $deviceType->id,
        'device_schema_version_id' => $schemaVersion->id,
        'external_id' => 'meter-001',
    ]);
    $topic = SchemaVersionTopic::factory()->publish()->create([
        'device_schema_version_id' => $schemaVersion->id,
        'key' => 'telemetry',
        'suffix' => 'telemetry',
    ]);

    return [
        'device' => $device,
        'topic' => $topic,
    ];
}

it('returns fresh latest telemetry from the hot-state store', function (): void {
    $context = createHotStateReaderContext();
    $stateStore = app(HotStateReaderFakeNatsDeviceStateStore::class);
    $stateStore->state = [
        'topic' => 'devices/meter-001/telemetry',
        'payload' => [
            'values' => [
                'temp_c' => '22.75',
            ],
            'telemetry_log_id' => 'telemetry-log-1',
            'ingestion_message_id' => 'ingestion-message-1',
            'status' => 'completed',
            'recorded_at' => CarbonImmutable::now()->subMinutes(2)->toIso8601String(),
        ],
        'stored_at' => CarbonImmutable::now()->subMinute()->toIso8601String(),
    ];

    $latestState = app(HotStateLatestTelemetryReader::class)->read(
        device: $context['device'],
        topic: $context['topic'],
        lookbackMinutes: 60,
    );

    expect($latestState)->not->toBeNull()
        ->and($latestState?->numericValue('temp_c'))->toBe(22.75)
        ->and($latestState?->telemetryLogId)->toBe('telemetry-log-1')
        ->and($stateStore->reads)->toBe(1)
        ->and($stateStore->lastRead)->toMatchArray([
            'device_uuid' => $context['device']->uuid,
            'topic' => 'devices/meter-001/telemetry',
            'host' => 'nats.internal',
            'port' => 4225,
        ]);
});

it('falls back to the database when hot-state reads are disabled', function (): void {
    config()->set('iot_dashboard.hot_state_reads.enabled', false);

    $context = createHotStateReaderContext();
    $stateStore = app(HotStateReaderFakeNatsDeviceStateStore::class);
    $stateStore->state = [
        'topic' => 'devices/meter-001/telemetry',
        'payload' => [
            'values' => ['temp_c' => 99],
            'recorded_at' => CarbonImmutable::now()->toIso8601String(),
        ],
        'stored_at' => CarbonImmutable::now()->toIso8601String(),
    ];

    $telemetryLog = DeviceTelemetryLog::factory()
        ->forDevice($context['device'])
        ->forTopic($context['topic'])
        ->create([
            'recorded_at' => CarbonImmutable::now()->subMinutes(5),
            'transformed_values' => ['temp_c' => 18.5],
        ]);

    $latestState = app(HotStateLatestTelemetryReader::class)->read(
        device: $context['device'],
        topic: $context['topic'],
        lookbackMinutes: 60,
    );

    expect($stateStore->reads)->toBe(0)
        ->and($latestState?->numericValue('temp_c'))->toBe(18.5)
        ->and($latestState?->telemetryLogId)->toBe($telemetryLog->id);
});

it('falls back to the database when hot state is stale or unavailable', function (): void {
    $context = createHotStateReaderContext();
    $stateStore = app(HotStateReaderFakeNatsDeviceStateStore::class);
    $stateStore->state = [
        'topic' => 'devices/meter-001/telemetry',
        'payload' => [
            'values' => ['temp_c' => 99],
            'recorded_at' => CarbonImmutable::now()->subHours(2)->toIso8601String(),
        ],
        'stored_at' => CarbonImmutable::now()->subHours(2)->toIso8601String(),
    ];

    DeviceTelemetryLog::factory()
        ->forDevice($context['device'])
        ->forTopic($context['topic'])
        ->create([
            'recorded_at' => CarbonImmutable::now()->subMinutes(5),
            'transformed_values' => ['temp_c' => 20.25],
        ]);

    $latestState = app(HotStateLatestTelemetryReader::class)->read(
        device: $context['device'],
        topic: $context['topic'],
        lookbackMinutes: 60,
    );

    expect($stateStore->reads)->toBe(1)
        ->and($latestState?->numericValue('temp_c'))->toBe(20.25);
});
