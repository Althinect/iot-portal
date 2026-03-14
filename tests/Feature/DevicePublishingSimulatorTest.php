<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Models\DeviceType;
use App\Domain\DeviceManagement\Publishing\DevicePublishingSimulator;
use App\Domain\DeviceManagement\Publishing\Nats\NatsPublisher;
use App\Domain\DeviceManagement\Publishing\Nats\NatsPublisherFactory;
use App\Domain\DeviceSchema\Enums\ParameterDataType;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Domain\DeviceSchema\Models\ParameterDefinition;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Events\DeviceConnectionChanged;
use App\Events\TelemetryIncoming;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Carbon::setTestNow();
});

it('simulates publishing and emits TelemetryIncoming events', function (): void {
    Event::fake([DeviceConnectionChanged::class, TelemetryIncoming::class]);

    $schemaVersion = DeviceSchemaVersion::factory()->create();

    $topic = SchemaVersionTopic::factory()->publish()->create([
        'device_schema_version_id' => $schemaVersion->id,
        'key' => 'telemetry',
        'label' => 'Telemetry',
        'suffix' => 'telemetry',
    ]);

    ParameterDefinition::factory()->create([
        'schema_version_topic_id' => $topic->id,
        'key' => 'temp',
        'json_path' => 'temp',
        'type' => ParameterDataType::Integer,
        'required' => true,
        'is_critical' => false,
        'mutation_expression' => null,
        'sequence' => 1,
        'is_active' => true,
    ]);

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
        'external_id' => 'fan-01',
    ]);

    $published = [];

    $fakePublisher = new class($published) implements NatsPublisher
    {
        /** @var array<int, array{subject: string, payload: string}> */
        public array $published;

        public function __construct(array &$published)
        {
            $this->published = &$published;
        }

        public function publish(string $subject, string $payload): void
        {
            $this->published[] = [
                'subject' => $subject,
                'payload' => $payload,
            ];
        }
    };

    $fakeFactory = new class($fakePublisher) implements NatsPublisherFactory
    {
        public function __construct(private NatsPublisher $publisher) {}

        public function make(string $host, int $port): NatsPublisher
        {
            return $this->publisher;
        }
    };

    app()->instance(NatsPublisherFactory::class, $fakeFactory);

    /** @var DevicePublishingSimulator $simulator */
    $simulator = app(DevicePublishingSimulator::class);

    $simulator->simulate(
        device: $device,
        count: 3,
        intervalSeconds: 0,
        schemaVersionTopicId: $topic->id,
    );

    $device->refresh();

    expect($published)->toHaveCount(3)
        ->and($published[0]['subject'])->toBe('devices.fan-01.telemetry')
        ->and($device->connection_state)->toBe('online')
        ->and($device->last_seen_at)->not->toBeNull();

    Event::assertDispatchedTimes(TelemetryIncoming::class, 3);
    Event::assertDispatched(DeviceConnectionChanged::class, 1);
});

it('increments counter telemetry values across simulation iterations', function (): void {
    $schemaVersion = DeviceSchemaVersion::factory()->create();

    $topic = SchemaVersionTopic::factory()->publish()->create([
        'device_schema_version_id' => $schemaVersion->id,
        'key' => 'telemetry',
        'label' => 'Telemetry',
        'suffix' => 'telemetry',
    ]);

    ParameterDefinition::factory()->create([
        'schema_version_topic_id' => $topic->id,
        'key' => 'total_energy_kwh',
        'json_path' => 'energy.total_energy_kwh',
        'type' => ParameterDataType::Decimal,
        'default_value' => 100.0,
        'validation_rules' => [
            'category' => 'counter',
            'increment_min' => 0.25,
            'increment_max' => 0.25,
        ],
        'required' => true,
        'is_critical' => false,
        'mutation_expression' => null,
        'sequence' => 1,
        'is_active' => true,
    ]);

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
        'external_id' => 'energy-meter-01',
    ]);

    $published = [];

    $fakePublisher = new class($published) implements NatsPublisher
    {
        /** @var array<int, array{subject: string, payload: string}> */
        public array $published;

        public function __construct(array &$published)
        {
            $this->published = &$published;
        }

        public function publish(string $subject, string $payload): void
        {
            $this->published[] = [
                'subject' => $subject,
                'payload' => $payload,
            ];
        }
    };

    $fakeFactory = new class($fakePublisher) implements NatsPublisherFactory
    {
        public function __construct(private NatsPublisher $publisher) {}

        public function make(string $host, int $port): NatsPublisher
        {
            return $this->publisher;
        }
    };

    app()->instance(NatsPublisherFactory::class, $fakeFactory);

    $capturedValues = [];

    /** @var DevicePublishingSimulator $simulator */
    $simulator = app(DevicePublishingSimulator::class);

    $simulator->simulate(
        device: $device,
        count: 4,
        intervalSeconds: 0,
        schemaVersionTopicId: $topic->id,
        onBeforePublish: function (int $iteration, string $mqttTopic, array $payload, SchemaVersionTopic $schemaTopic) use (&$capturedValues): void {
            $capturedValues[] = (float) data_get($payload, 'energy.total_energy_kwh');
        },
    );

    expect($capturedValues)->toHaveCount(4)
        ->and($capturedValues[0])->toBeLessThan($capturedValues[1])
        ->and($capturedValues[1])->toBeLessThan($capturedValues[2])
        ->and($capturedValues[2])->toBeLessThan($capturedValues[3]);
});

it('allows simulated devices to be marked offline by the health check after activity stops', function (): void {
    Event::fake([DeviceConnectionChanged::class, TelemetryIncoming::class]);
    Carbon::setTestNow(Carbon::parse('2026-03-08 11:00:00'));

    $schemaVersion = DeviceSchemaVersion::factory()->create();

    $topic = SchemaVersionTopic::factory()->publish()->create([
        'device_schema_version_id' => $schemaVersion->id,
        'suffix' => 'telemetry',
    ]);

    ParameterDefinition::factory()->create([
        'schema_version_topic_id' => $topic->id,
        'key' => 'temp',
        'json_path' => 'temp',
        'type' => ParameterDataType::Integer,
        'required' => true,
        'is_critical' => false,
        'mutation_expression' => null,
        'sequence' => 1,
        'is_active' => true,
    ]);

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
        'external_id' => 'pump-01',
        'connection_state' => 'offline',
    ]);

    $published = [];

    $fakePublisher = new class($published) implements NatsPublisher
    {
        /** @var array<int, array{subject: string, payload: string}> */
        public array $published;

        public function __construct(array &$published)
        {
            $this->published = &$published;
        }

        public function publish(string $subject, string $payload): void
        {
            $this->published[] = [
                'subject' => $subject,
                'payload' => $payload,
            ];
        }
    };

    $fakeFactory = new class($fakePublisher) implements NatsPublisherFactory
    {
        public function __construct(private NatsPublisher $publisher) {}

        public function make(string $host, int $port): NatsPublisher
        {
            return $this->publisher;
        }
    };

    app()->instance(NatsPublisherFactory::class, $fakeFactory);

    /** @var DevicePublishingSimulator $simulator */
    $simulator = app(DevicePublishingSimulator::class);

    $simulator->simulate(
        device: $device,
        count: 1,
        intervalSeconds: 0,
        schemaVersionTopicId: $topic->id,
    );

    $device->refresh();

    expect($device->connection_state)->toBe('online')
        ->and($device->last_seen_at)->not->toBeNull();

    Carbon::setTestNow(Carbon::parse('2026-03-08 11:06:00'));

    $this->artisan('iot:check-device-health')
        ->assertSuccessful();

    $device->refresh();

    expect($device->connection_state)->toBe('offline')
        ->and($device->offline_deadline_at)->toBeNull();

    Event::assertDispatched(DeviceConnectionChanged::class, 2);
    Event::assertDispatched(DeviceConnectionChanged::class, fn (DeviceConnectionChanged $event): bool => $event->deviceId === $device->id && $event->connectionState === 'offline');
});
