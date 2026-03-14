<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Models\DeviceType;
use App\Domain\DeviceManagement\Publishing\FleetTelemetryLoadGenerator;
use App\Domain\DeviceManagement\Publishing\Nats\NatsPublisher;
use App\Domain\DeviceManagement\Publishing\Nats\NatsPublisherFactory;
use App\Domain\DeviceSchema\Enums\ParameterDataType;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Domain\DeviceSchema\Models\ParameterDefinition;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Events\DeviceConnectionChanged;
use App\Events\TelemetryIncoming;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

it('reuses a single publisher connection across the fleet run', function (): void {
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

    $devices = Device::factory()->count(2)->create([
        'device_type_id' => $deviceType->id,
        'device_schema_version_id' => $schemaVersion->id,
        'connection_state' => 'offline',
    ]);

    $devices[0]->forceFill(['external_id' => 'device-01'])->save();
    $devices[1]->forceFill(['external_id' => 'device-02'])->save();

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
        public int $makeCalls = 0;

        public function __construct(private readonly NatsPublisher $publisher) {}

        public function make(string $host, int $port): NatsPublisher
        {
            expect($host)->toBe((string) config('iot.nats.host'))
                ->and($port)->toBe((int) config('iot.nats.port'));

            $this->makeCalls++;

            return $this->publisher;
        }
    };

    app()->instance(NatsPublisherFactory::class, $fakeFactory);

    /** @var FleetTelemetryLoadGenerator $generator */
    $generator = app(FleetTelemetryLoadGenerator::class);

    $summary = $generator->run(
        devices: $devices->load('deviceType', 'schemaVersion.topics.parameters'),
        count: 3,
        intervalSeconds: 0,
        schemaVersionTopicId: $topic->id,
    );

    expect($fakeFactory->makeCalls)->toBe(1)
        ->and($published)->toHaveCount(6)
        ->and($published[0]['subject'])->toBe('devices.device-01.telemetry')
        ->and($published[1]['subject'])->toBe('devices.device-02.telemetry')
        ->and($summary)->toBe([
            'device_count' => 2,
            'completed_iterations' => 3,
            'published_device_iterations' => 6,
            'published_messages' => 6,
        ]);

    Event::assertDispatchedTimes(TelemetryIncoming::class, 6);
    Event::assertDispatchedTimes(DeviceConnectionChanged::class, 2);
});

it('returns an empty summary when no devices are selected', function (): void {
    /** @var FleetTelemetryLoadGenerator $generator */
    $generator = app(FleetTelemetryLoadGenerator::class);

    expect($generator->run(collect(), count: 5, intervalSeconds: 0))->toBe([
        'device_count' => 0,
        'completed_iterations' => 0,
        'published_device_iterations' => 0,
        'published_messages' => 0,
    ]);
});
