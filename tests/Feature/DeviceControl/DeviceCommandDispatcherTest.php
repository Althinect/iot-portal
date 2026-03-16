<?php

declare(strict_types=1);

use App\Domain\DeviceControl\Enums\CommandStatus;
use App\Domain\DeviceControl\Models\DeviceCommandLog;
use App\Domain\DeviceControl\Models\DeviceDesiredTopicState;
use App\Domain\DeviceControl\Services\DeviceCommandDispatcher;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Models\DeviceType;
use App\Domain\DeviceManagement\Publishing\Mqtt\MqttCommandPublisher;
use App\Domain\DeviceSchema\Enums\ParameterDataType;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Domain\DeviceSchema\Models\ParameterDefinition;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Events\CommandDispatched;
use App\Events\CommandSent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function createDeviceWithSubscribeTopic(): array
{
    $schemaVersion = DeviceSchemaVersion::factory()->create();

    $subscribeTopic = SchemaVersionTopic::factory()->subscribe()->create([
        'device_schema_version_id' => $schemaVersion->id,
        'key' => 'control',
        'label' => 'Control',
        'suffix' => 'control',
        'qos' => 2,
        'retain' => false,
    ]);

    ParameterDefinition::factory()->create([
        'schema_version_topic_id' => $subscribeTopic->id,
        'key' => 'power',
        'json_path' => 'power',
        'type' => ParameterDataType::String,
        'default_value' => 'off',
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
        'external_id' => 'pump-42',
    ]);

    return [$device, $subscribeTopic];
}

function bindFakeMqttPublisher(): object
{
    $fakePublisher = new class implements MqttCommandPublisher
    {
        /** @var array<int, array{topic: string, payload: string, host: string, port: int}> */
        public array $published = [];

        public function publish(string $mqttTopic, string $payload, string $host, int $port): void
        {
            $this->published[] = [
                'topic' => $mqttTopic,
                'payload' => $payload,
                'host' => $host,
                'port' => $port,
            ];
        }
    };

    app()->instance(MqttCommandPublisher::class, $fakePublisher);

    return $fakePublisher;
}

it('creates a command log with pending status and broadcasts CommandDispatched', function (): void {
    Event::fake([CommandDispatched::class, CommandSent::class]);

    [$device, $topic] = createDeviceWithSubscribeTopic();
    $fakePublisher = bindFakeMqttPublisher();

    /** @var DeviceCommandDispatcher $dispatcher */
    $dispatcher = app(DeviceCommandDispatcher::class);

    $commandLog = $dispatcher->dispatch(
        device: $device,
        topic: $topic,
        payload: ['power' => 'on'],
        userId: null,
    );

    expect($commandLog)->toBeInstanceOf(DeviceCommandLog::class)
        ->and($commandLog->device_id)->toBe($device->id)
        ->and($commandLog->schema_version_topic_id)->toBe($topic->id)
        ->and($commandLog->command_payload)->toBe(['power' => 'on']);

    Event::assertDispatched(CommandDispatched::class);
});

it('publishes to correct MQTT topic and updates status to sent', function (): void {
    Event::fake([CommandDispatched::class, CommandSent::class]);

    [$device, $topic] = createDeviceWithSubscribeTopic();
    $fakePublisher = bindFakeMqttPublisher();

    /** @var DeviceCommandDispatcher $dispatcher */
    $dispatcher = app(DeviceCommandDispatcher::class);

    $commandLog = $dispatcher->dispatch(
        device: $device,
        topic: $topic,
        payload: ['power' => 'on'],
    );

    /** @var array<string, mixed>|null $publishedPayload */
    $publishedPayload = json_decode($fakePublisher->published[0]['payload'], true);

    expect($fakePublisher->published)->toHaveCount(1)
        ->and($fakePublisher->published[0]['topic'])->toBe('devices/pump-42/control')
        ->and($publishedPayload)->toBeArray()
        ->and(data_get($publishedPayload, 'power'))->toBe('on')
        ->and(data_get($publishedPayload, '_meta.command_id'))->toBe($commandLog->correlation_id)
        ->and($commandLog->status)->toBe(CommandStatus::Sent)
        ->and($commandLog->sent_at)->not->toBeNull()
        ->and($commandLog->correlation_id)->not->toBeNull();

    Event::assertDispatched(CommandSent::class, function (CommandSent $event) use ($commandLog): bool {
        return $event->commandLog->id === $commandLog->id
            && $event->natsSubject === 'devices.pump-42.control';
    });
});

it('marks command as failed when MQTT publish throws', function (): void {
    Event::fake([CommandDispatched::class, CommandSent::class]);

    [$device, $topic] = createDeviceWithSubscribeTopic();

    $failingPublisher = new class implements MqttCommandPublisher
    {
        public int $attempts = 0;

        public function publish(string $mqttTopic, string $payload, string $host, int $port): void
        {
            $this->attempts++;

            throw new RuntimeException('MQTT connection refused');
        }
    };

    app()->instance(MqttCommandPublisher::class, $failingPublisher);

    /** @var DeviceCommandDispatcher $dispatcher */
    $dispatcher = app(DeviceCommandDispatcher::class);

    $commandLog = $dispatcher->dispatch(
        device: $device,
        topic: $topic,
        payload: ['power' => 'on'],
    );

    expect($commandLog->status)->toBe(CommandStatus::Failed)
        ->and($commandLog->error_message)->toBe('MQTT connection refused')
        ->and($failingPublisher->attempts)->toBe(1);

    Event::assertDispatched(CommandDispatched::class);
    Event::assertNotDispatched(CommandSent::class);
});

it('retries transient MQTT publish failures once and sends successfully', function (): void {
    Event::fake([CommandDispatched::class, CommandSent::class]);

    [$device, $topic] = createDeviceWithSubscribeTopic();

    $retryingPublisher = new class implements MqttCommandPublisher
    {
        public int $attempts = 0;

        public function publish(string $mqttTopic, string $payload, string $host, int $port): void
        {
            $this->attempts++;

            if ($this->attempts === 1) {
                throw new RuntimeException('MQTT socket read failed or connection closed');
            }
        }
    };

    app()->instance(MqttCommandPublisher::class, $retryingPublisher);

    /** @var DeviceCommandDispatcher $dispatcher */
    $dispatcher = app(DeviceCommandDispatcher::class);

    $commandLog = $dispatcher->dispatch(
        device: $device,
        topic: $topic,
        payload: ['power' => 'on'],
    );

    expect($retryingPublisher->attempts)->toBe(2)
        ->and($commandLog->status)->toBe(CommandStatus::Sent)
        ->and($commandLog->error_message)->toBeNull();

    Event::assertDispatched(CommandSent::class);
});

it('stores the command log in the database', function (): void {
    Event::fake([CommandDispatched::class, CommandSent::class]);

    [$device, $topic] = createDeviceWithSubscribeTopic();
    bindFakeMqttPublisher();

    /** @var DeviceCommandDispatcher $dispatcher */
    $dispatcher = app(DeviceCommandDispatcher::class);

    $dispatcher->dispatch(
        device: $device,
        topic: $topic,
        payload: ['power' => 'on', 'mode' => 'auto'],
    );

    $this->assertDatabaseHas('device_command_logs', [
        'device_id' => $device->id,
        'schema_version_topic_id' => $topic->id,
        'status' => CommandStatus::Sent->value,
    ]);
});

it('upserts desired topic state when dispatching commands', function (): void {
    Event::fake([CommandDispatched::class, CommandSent::class]);

    [$device, $topic] = createDeviceWithSubscribeTopic();
    bindFakeMqttPublisher();

    /** @var DeviceCommandDispatcher $dispatcher */
    $dispatcher = app(DeviceCommandDispatcher::class);

    $first = $dispatcher->dispatch(
        device: $device,
        topic: $topic,
        payload: ['power' => 'on'],
    );

    $second = $dispatcher->dispatch(
        device: $device,
        topic: $topic,
        payload: ['power' => 'off'],
    );

    $state = DeviceDesiredTopicState::query()
        ->where('device_id', $device->id)
        ->where('schema_version_topic_id', $topic->id)
        ->first();

    expect($state)->not->toBeNull()
        ->and($state->desired_payload)->toBe(['power' => 'off'])
        ->and($state->correlation_id)->toBe($second->correlation_id)
        ->and($state->reconciled_at)->toBeNull();
});

it('uses configured MQTT host and port when dispatch host and port are not provided', function (): void {
    Event::fake([CommandDispatched::class, CommandSent::class]);
    config([
        'iot.mqtt.host' => '10.77.0.12',
        'iot.mqtt.port' => 1884,
    ]);

    [$device, $topic] = createDeviceWithSubscribeTopic();

    $fakePublisher = new class implements MqttCommandPublisher
    {
        /** @var array<int, array{topic: string, payload: string, host: string, port: int}> */
        public array $published = [];

        public function publish(string $mqttTopic, string $payload, string $host, int $port): void
        {
            $this->published[] = [
                'topic' => $mqttTopic,
                'payload' => $payload,
                'host' => $host,
                'port' => $port,
            ];
        }
    };

    app()->instance(MqttCommandPublisher::class, $fakePublisher);

    /** @var DeviceCommandDispatcher $dispatcher */
    $dispatcher = app(DeviceCommandDispatcher::class);

    $dispatcher->dispatch(
        device: $device,
        topic: $topic,
        payload: ['power' => 'on'],
    );

    expect($fakePublisher->published[0]['host'])->toBe('10.77.0.12')
        ->and($fakePublisher->published[0]['port'])->toBe(1884)
        ->and($fakePublisher->published)->toHaveCount(1);
});
