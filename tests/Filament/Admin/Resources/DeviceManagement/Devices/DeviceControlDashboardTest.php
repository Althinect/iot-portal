<?php

declare(strict_types=1);

use App\Domain\DeviceControl\Enums\CommandStatus;
use App\Domain\DeviceControl\Models\DeviceCommandLog;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Models\DeviceType;
use App\Domain\DeviceManagement\Publishing\Mqtt\MqttCommandPublisher;
use App\Domain\DeviceManagement\Publishing\Nats\NatsDeviceStateStore;
use App\Domain\DeviceSchema\Enums\ParameterDataType;
use App\Domain\DeviceSchema\Enums\TopicDirection;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Domain\DeviceSchema\Models\ParameterDefinition;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Domain\Shared\Models\User;
use App\Events\CommandDispatched;
use App\Events\CommandSent;
use App\Filament\Admin\Resources\DeviceManagement\Devices\DeviceResource;
use App\Filament\Admin\Resources\DeviceManagement\Devices\Pages\DeviceControlDashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function createTestDeviceForDashboard(): Device
{
    $schemaVersion = DeviceSchemaVersion::factory()->create([
        'firmware_template' => 'const char* DEVICE_ID = "{{DEVICE_ID}}";',
    ]);

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

    $publishTopic = SchemaVersionTopic::factory()->publish()->create([
        'device_schema_version_id' => $schemaVersion->id,
        'key' => 'state',
        'label' => 'State',
        'suffix' => 'state',
        'qos' => 2,
        'retain' => true,
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

    return Device::factory()->create([
        'device_type_id' => $deviceType->id,
        'device_schema_version_id' => $schemaVersion->id,
        'external_id' => 'pump-42',
    ]);
}

function bindDashboardFakeNats(): void
{
    $fakePublisher = new class implements MqttCommandPublisher
    {
        public function publish(string $mqttTopic, string $payload, string $host, int $port): void {}
    };

    app()->instance(MqttCommandPublisher::class, $fakePublisher);
}

function bindFakeDeviceStateStoreForDashboard(?array $returnState = null): void
{
    $fakeStore = new class($returnState) implements NatsDeviceStateStore
    {
        /** @var array<string, array<string, array{topic: string, payload: array<string, mixed>, stored_at: string}>> */
        public array $storedByDevice = [];

        /**
         * @param  array{topic: string, payload: array<string, mixed>, stored_at: string}|null  $returnState
         */
        public function __construct(private ?array $returnState)
        {
            if ($returnState !== null) {
                $topic = $returnState['topic'] ?? 'unknown/topic';
                $this->storedByDevice['default'][$topic] = $returnState;
            }
        }

        public function store(string $deviceUuid, string $topic, array $payload, string $host = '127.0.0.1', int $port = 4223): void
        {
            $this->storedByDevice[$deviceUuid][$topic] = [
                'topic' => $topic,
                'payload' => $payload,
                'stored_at' => now()->toIso8601String(),
            ];
        }

        public function getLastState(string $deviceUuid, string $host = '127.0.0.1', int $port = 4223): ?array
        {
            return $this->returnState;
        }

        public function getAllStates(string $deviceUuid, string $host = '127.0.0.1', int $port = 4223): array
        {
            if ($this->returnState !== null) {
                return [$this->returnState];
            }

            return array_values($this->storedByDevice[$deviceUuid] ?? []);
        }

        public function getStateByTopic(string $deviceUuid, string $topic, string $host = '127.0.0.1', int $port = 4223): ?array
        {
            if ($this->returnState !== null && ($this->returnState['topic'] ?? null) === $topic) {
                return $this->returnState;
            }

            return $this->storedByDevice[$deviceUuid][$topic] ?? null;
        }
    };

    app()->instance(NatsDeviceStateStore::class, $fakeStore);
}

beforeEach(function (): void {
    $this->admin = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($this->admin);
    bindFakeDeviceStateStoreForDashboard();
});

it('can render the control dashboard page', function (): void {
    $device = createTestDeviceForDashboard();

    livewire(DeviceControlDashboard::class, ['record' => $device->id])
        ->assertSuccessful();
});

it('can open the control dashboard route', function (): void {
    $device = createTestDeviceForDashboard();

    $this->get(DeviceResource::getUrl('control-dashboard', ['record' => $device]))
        ->assertSuccessful();
});

it('displays the device name in the title', function (): void {
    $device = createTestDeviceForDashboard();

    livewire(DeviceControlDashboard::class, ['record' => $device->id])
        ->assertSee($device->name);
});

it('shows subscribe topic options', function (): void {
    $device = createTestDeviceForDashboard();

    livewire(DeviceControlDashboard::class, ['record' => $device->id])
        ->assertSee('Control (control)');
});

it('shows firmware viewer action on the control dashboard page', function (): void {
    $device = createTestDeviceForDashboard();

    livewire(DeviceControlDashboard::class, ['record' => $device->id])
        ->assertActionExists('viewFirmware');
});

it('can open firmware modal from control dashboard and see rendered firmware', function (): void {
    $device = createTestDeviceForDashboard();

    livewire(DeviceControlDashboard::class, ['record' => $device->id])
        ->mountAction('viewFirmware')
        ->assertActionMounted('viewFirmware')
        ->assertActionDataSet(function (array $data): bool {
            $firmware = $data['firmware'] ?? null;

            return is_string($firmware) && str_contains($firmware, 'const char* DEVICE_ID = "pump-42";');
        });
});

it('loads default payload JSON for the selected topic', function (): void {
    $device = createTestDeviceForDashboard();

    $component = livewire(DeviceControlDashboard::class, ['record' => $device->id]);

    expect($component->get('commandPayloadJson'))->toContain('power');
});

it('sends a command via the dispatcher and creates a log', function (): void {
    Event::fake([CommandDispatched::class, CommandSent::class]);

    $device = createTestDeviceForDashboard();
    bindDashboardFakeNats();

    $topic = $device->schemaVersion->topics->firstWhere('direction', TopicDirection::Subscribe);

    livewire(DeviceControlDashboard::class, ['record' => $device->id])
        ->set('selectedTopicId', (string) $topic->id)
        ->set('useAdvancedJson', true)
        ->set('commandPayloadJson', json_encode(['power' => 'on']))
        ->call('sendCommand')
        ->assertNotified('Command sent');

    $this->assertDatabaseHas('device_command_logs', [
        'device_id' => $device->id,
        'schema_version_topic_id' => $topic->id,
        'status' => CommandStatus::Sent->value,
    ]);
});

it('shows command history in the table', function (): void {
    $device = createTestDeviceForDashboard();

    $topic = $device->schemaVersion->topics->firstWhere('direction', TopicDirection::Subscribe);

    DeviceCommandLog::factory()->sent()->create([
        'device_id' => $device->id,
        'schema_version_topic_id' => $topic->id,
        'command_payload' => ['power' => 'on'],
    ]);

    livewire(DeviceControlDashboard::class, ['record' => $device->id])
        ->assertCanSeeTableRecords(DeviceCommandLog::where('device_id', $device->id)->get());
});

it('validates invalid JSON before sending', function (): void {
    $device = createTestDeviceForDashboard();
    $topic = $device->schemaVersion->topics->firstWhere('direction', TopicDirection::Subscribe);

    livewire(DeviceControlDashboard::class, ['record' => $device->id])
        ->set('selectedTopicId', (string) $topic->id)
        ->set('useAdvancedJson', true)
        ->set('commandPayloadJson', 'not-valid-json')
        ->call('sendCommand')
        ->assertNotified('Invalid JSON');
});

it('warns when no subscribe topics are available', function (): void {
    $schemaVersion = DeviceSchemaVersion::factory()->create();

    $publishTopic = SchemaVersionTopic::factory()->publish()->create([
        'device_schema_version_id' => $schemaVersion->id,
        'key' => 'state',
        'label' => 'State',
        'suffix' => 'state',
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
    ]);

    livewire(DeviceControlDashboard::class, ['record' => $device->id])
        ->call('sendCommand')
        ->assertNotified('No topic selected');
});

it('loads initial device state from the NATS KV store on mount', function (): void {
    $device = createTestDeviceForDashboard();

    bindFakeDeviceStateStoreForDashboard([
        'topic' => 'devices/pump-42/state',
        'payload' => ['brightness' => 75, 'power' => 'on'],
        'stored_at' => '2025-01-15T10:30:00+00:00',
    ]);

    $component = livewire(DeviceControlDashboard::class, ['record' => $device->id]);

    expect($component->get('initialDeviceState'))
        ->not->toBeNull()
        ->and($component->get('initialDeviceState.topic'))->toBe('devices/pump-42/state')
        ->and($component->get('initialDeviceState.payload'))->toBe(['brightness' => 75, 'power' => 'on'])
        ->and($component->get('initialDeviceState.stored_at'))->toBe('2025-01-15T10:30:00+00:00');
});

it('renders with null initial state when no state is stored', function (): void {
    $device = createTestDeviceForDashboard();

    bindFakeDeviceStateStoreForDashboard(null);

    $component = livewire(DeviceControlDashboard::class, ['record' => $device->id]);

    expect($component->get('initialDeviceState'))->toBeNull();
});

it('handles NATS failure gracefully on mount', function (): void {
    $device = createTestDeviceForDashboard();

    $failingStore = new class implements NatsDeviceStateStore
    {
        public function store(string $deviceUuid, string $topic, array $payload, string $host = '127.0.0.1', int $port = 4223): void {}

        public function getLastState(string $deviceUuid, string $host = '127.0.0.1', int $port = 4223): ?array
        {
            throw new RuntimeException('NATS connection refused');
        }

        public function getAllStates(string $deviceUuid, string $host = '127.0.0.1', int $port = 4223): array
        {
            throw new RuntimeException('NATS connection refused');
        }

        public function getStateByTopic(string $deviceUuid, string $topic, string $host = '127.0.0.1', int $port = 4223): ?array
        {
            throw new RuntimeException('NATS connection refused');
        }
    };

    app()->instance(NatsDeviceStateStore::class, $failingStore);

    $component = livewire(DeviceControlDashboard::class, ['record' => $device->id]);

    expect($component->get('initialDeviceState'))->toBeNull();
    $component->assertSuccessful();
});

it('renders color picker controls when widget is configured as color', function (): void {
    $schemaVersion = DeviceSchemaVersion::factory()->create();

    $subscribeTopic = SchemaVersionTopic::factory()->subscribe()->create([
        'device_schema_version_id' => $schemaVersion->id,
        'key' => 'control',
        'label' => 'Control',
        'suffix' => 'control',
    ]);

    ParameterDefinition::factory()->create([
        'schema_version_topic_id' => $subscribeTopic->id,
        'key' => 'color_hex',
        'label' => 'Color',
        'json_path' => 'color_hex',
        'type' => ParameterDataType::String,
        'default_value' => '#ff0000',
        'validation_rules' => ['regex' => '/^#([A-Fa-f0-9]{6})$/'],
        'control_ui' => ['widget' => 'color'],
        'required' => true,
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
    ]);

    $component = livewire(DeviceControlDashboard::class, ['record' => $device->id]);

    expect($component->get('controlSchema.0.widget'))->toBe('color')
        ->and($component->get('controlValues.color_hex'))->toBe('#ff0000');
});

it('updates control values from incoming device state payload', function (): void {
    $device = createTestDeviceForDashboard();

    $component = livewire(DeviceControlDashboard::class, ['record' => $device->id]);

    expect($component->get('controlValues.power'))->toBe('off');

    $component->call('updateControlValuesFromState', ['power' => 'on']);

    expect($component->get('controlValues.power'))->toBe('on');
});

it('updates control values from incoming wrapped device state payload', function (): void {
    $device = createTestDeviceForDashboard();

    $component = livewire(DeviceControlDashboard::class, ['record' => $device->id]);

    expect($component->get('controlValues.power'))->toBe('off');

    $component->call('updateControlValuesFromState', [
        'values' => ['power' => 'on'],
    ]);

    expect($component->get('controlValues.power'))->toBe('on');
});

it('ignores unknown keys in state payload during update', function (): void {
    $device = createTestDeviceForDashboard();

    $component = livewire(DeviceControlDashboard::class, ['record' => $device->id]);

    $component->call('updateControlValuesFromState', [
        'power' => 'on',
        'unknown_field' => 'some-value',
    ]);

    expect($component->get('controlValues.power'))->toBe('on')
        ->and($component->get('controlValues'))->not->toHaveKey('unknown_field');
});

it('applies initial device state to control values on mount', function (): void {
    $device = createTestDeviceForDashboard();

    bindFakeDeviceStateStoreForDashboard([
        'topic' => 'devices/pump-42/state',
        'payload' => ['power' => 'on'],
        'stored_at' => '2025-01-15T10:30:00+00:00',
    ]);

    $component = livewire(DeviceControlDashboard::class, ['record' => $device->id]);

    expect($component->get('controlValues.power'))->toBe('on');
});

it('applies wrapped initial device state values to control values on mount', function (): void {
    $device = createTestDeviceForDashboard();

    bindFakeDeviceStateStoreForDashboard([
        'topic' => 'devices/pump-42/state',
        'payload' => ['values' => ['power' => 'on']],
        'stored_at' => '2025-01-15T10:30:00+00:00',
    ]);

    $component = livewire(DeviceControlDashboard::class, ['record' => $device->id]);

    expect($component->get('controlValues.power'))->toBe('on');
});
