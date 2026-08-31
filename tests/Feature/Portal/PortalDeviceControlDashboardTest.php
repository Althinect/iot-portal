<?php

declare(strict_types=1);

use App\Domain\Authorization\Enums\TenantRole;
use App\Domain\Authorization\Permissions\RolePermission;
use App\Domain\Authorization\Services\TenantRoleManager;
use App\Domain\DeviceControl\Enums\CommandStatus;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Permissions\DevicePermission;
use App\Domain\DeviceManagement\Publishing\Mqtt\MqttCommandPublisher;
use App\Domain\DeviceManagement\Publishing\Nats\NatsDeviceStateStore;
use App\Domain\DeviceManagement\ValueObjects\Protocol\MqttProtocolConfig;
use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use App\Domain\Shared\Permissions\UserPermission;
use App\Domain\Telemetry\Permissions\DeviceTelemetryLogPermission;
use App\Filament\Portal\Resources\DeviceManagement\Devices\DeviceResource;
use App\Filament\Portal\Resources\DeviceManagement\Devices\Pages\DeviceControlDashboard;
use App\Filament\Portal\Resources\DeviceManagement\Devices\Pages\ListDevices;
use App\Filament\Portal\Resources\DeviceManagement\Devices\Pages\ViewDevice;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function createPortalControllableDevice(Organization $organization, string $externalId = 'portal-alarm'): Device
{
    $profileVersion = DeviceProfileVersion::factory()->active()->mqtt()->create([
        'protocol_config' => (new MqttProtocolConfig(
            brokerHost: 'localhost',
            brokerPort: 1883,
            username: null,
            password: null,
            useTls: false,
            baseTopic: 'devices',
        ))->toArray(),
    ]);

    $commandChannel = DeviceChannel::factory()->command()->create([
        'device_profile_version_id' => $profileVersion->id,
        'key' => 'control',
        'label' => 'Control',
        'address' => 'control',
        'qos' => 1,
    ]);

    ProfileParameterDefinition::factory()->create([
        'device_channel_id' => $commandChannel->id,
        'key' => 'alarm_on',
        'label' => 'Alarm',
        'json_path' => 'alarm_on',
        'type' => ParameterDataType::Boolean,
        'default_value' => false,
        'required' => true,
        'is_critical' => false,
        'mutation_expression' => null,
        'sequence' => 1,
        'is_active' => true,
    ]);

    return Device::factory()->create([
        'organization_id' => $organization->id,
        'device_profile_version_id' => $profileVersion->id,
        'external_id' => $externalId,
    ])->load('profileVersion.channels.parameters');
}

function bindPortalControlDashboardFakes(): void
{
    app()->instance(MqttCommandPublisher::class, new class implements MqttCommandPublisher
    {
        public function publish(string $mqttTopic, string $payload, string $host, int $port): void {}
    });

    app()->instance(NatsDeviceStateStore::class, new class implements NatsDeviceStateStore
    {
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
            return null;
        }
    });
}

function activatePortalControlTenant(Organization $organization): void
{
    setPermissionsTeamId($organization->id);
    Filament::setCurrentPanel('portal');
    Filament::setTenant($organization);
    Filament::bootCurrentPanel();
}

beforeEach(function (): void {
    $this->organization = Organization::factory()->create(['slug' => 'portal-control']);
    $this->otherOrganization = Organization::factory()->create(['slug' => 'portal-control-other']);
    $this->portalUser = User::factory()->create();
    $this->portalUser->organizations()->attach($this->organization);
    app(TenantRoleManager::class)->assign($this->portalUser, $this->organization, TenantRole::Operator);

    foreach ([
        DevicePermission::VIEW_ANY->value,
        DevicePermission::VIEW->value,
        DeviceTelemetryLogPermission::VIEW_ANY->value,
        DeviceTelemetryLogPermission::VIEW->value,
        RolePermission::VIEW_ANY->value,
        UserPermission::VIEW_ANY->value,
    ] as $permissionName) {
        Permission::findOrCreate($permissionName, 'web');
    }

    $this->actingAs($this->portalUser);

    bindPortalControlDashboardFakes();
});

it('shows and opens the control dashboard for a device in the current portal tenant', function (): void {
    $device = createPortalControllableDevice($this->organization);
    activatePortalControlTenant($this->organization);

    livewire(ListDevices::class)
        ->assertCanSeeTableRecords([$device])
        ->assertActionVisible(TestAction::make('controlDashboard')->table($device));

    livewire(ViewDevice::class, ['record' => $device->id])
        ->assertSuccessful()
        ->assertActionExists('controlDashboard');

    $this->get(DeviceResource::getUrl('control-dashboard', ['record' => $device]))
        ->assertSuccessful()
        ->assertSee('Control Dashboard')
        ->assertSee($device->name);

    livewire(DeviceControlDashboard::class, ['record' => $device->id])
        ->assertSuccessful()
        ->assertSee('Control (control)');
});

it('returns not found for another organizations device control dashboard', function (): void {
    $otherDevice = createPortalControllableDevice($this->otherOrganization, 'other-alarm');
    activatePortalControlTenant($this->organization);

    expect((int) $otherDevice->organization_id)->toBe((int) $this->otherOrganization->id)
        ->and($this->portalUser->organizations()->pluck('organizations.id')->all())->toBe([$this->organization->id])
        ->and($this->portalUser->can('view', $otherDevice))->toBeFalse();

    $this->get(DeviceResource::getUrl('control-dashboard', ['record' => $otherDevice]))
        ->assertNotFound();
});

it('allows a portal user to control their own device and records the actor', function (): void {
    $device = createPortalControllableDevice($this->organization);
    activatePortalControlTenant($this->organization);
    $channel = $device->profileVersion?->channels
        ->first(fn (DeviceChannel $deviceChannel): bool => $deviceChannel->isSubscribe());

    livewire(DeviceControlDashboard::class, ['record' => $device->id])
        ->set('selectedChannelId', (string) $channel?->id)
        ->set('useAdvancedJson', true)
        ->set('commandPayloadJson', json_encode(['alarm_on' => true]))
        ->call('sendCommand')
        ->assertNotified('Command sent');

    $this->assertDatabaseHas('device_command_logs', [
        'device_id' => $device->id,
        'device_channel_id' => $channel?->id,
        'user_id' => $this->portalUser->id,
        'status' => CommandStatus::Sent->value,
    ]);
});

it('rejects a command channel that does not belong to the selected device', function (): void {
    $device = createPortalControllableDevice($this->organization);
    $otherDevice = createPortalControllableDevice($this->organization, 'second-alarm');
    activatePortalControlTenant($this->organization);
    $otherChannel = $otherDevice->profileVersion?->channels
        ->first(fn (DeviceChannel $deviceChannel): bool => $deviceChannel->isSubscribe());

    livewire(DeviceControlDashboard::class, ['record' => $device->id])
        ->set('selectedChannelId', (string) $otherChannel?->id)
        ->set('useAdvancedJson', true)
        ->set('commandPayloadJson', json_encode(['alarm_on' => true]))
        ->call('sendCommand')
        ->assertNotified('Channel not found');

    $this->assertDatabaseMissing('device_command_logs', [
        'device_id' => $device->id,
        'device_channel_id' => $otherChannel?->id,
    ]);
});
