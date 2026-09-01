<?php

declare(strict_types=1);

use App\Domain\Authorization\Enums\TenantRole;
use App\Domain\Authorization\Services\TenantRoleManager;
use App\Domain\DeviceManagement\Enums\MqttSecurityMode;
use App\Domain\DeviceManagement\Jobs\SimulateDevicePublishingJob;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\ValueObjects\Protocol\MqttProtocolConfig;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\DeviceProfile;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\Shared\Models\Entity;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use App\Filament\Portal\Resources\DeviceManagement\Devices\DeviceResource;
use App\Filament\Portal\Resources\DeviceManagement\Devices\Pages\CreateDevice;
use App\Filament\Portal\Resources\DeviceManagement\Devices\Pages\DeviceControlDashboard;
use App\Filament\Portal\Resources\DeviceManagement\Devices\Pages\DeviceCredentials;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->organization = Organization::factory()->create(['slug' => 'setup-test']);
    $this->user = User::factory()->create();
    $this->user->organizations()->attach($this->organization);
    $profile = DeviceProfile::factory()->global()->create();
    $this->profileVersion = DeviceProfileVersion::factory()->active()->mqtt()->create([
        'device_profile_id' => $profile->id,
        'protocol_config' => new MqttProtocolConfig(
            brokerHost: 'mqtt.example.test',
            brokerPort: 8883,
            useTls: true,
            baseTopic: 'tenant/devices',
            securityMode: MqttSecurityMode::X509Mtls,
        ),
    ]);
    $this->publishChannel = DeviceChannel::factory()->telemetry()->create([
        'device_profile_version_id' => $this->profileVersion->id,
        'key' => 'telemetry',
        'label' => 'Telemetry',
        'address' => 'telemetry',
    ]);
    $this->device = Device::factory()->create([
        'organization_id' => $this->organization->id,
        'device_profile_version_id' => $this->profileVersion->id,
        'external_id' => 'portal-device-01',
    ]);

    $this->actingAs($this->user);
    Filament::setCurrentPanel('portal');
    Filament::setTenant($this->organization);
    Filament::bootCurrentPanel();
});

afterEach(function (): void {
    setPermissionsTeamId(null);
});

it('allows operators to queue tenant device telemetry simulations', function (): void {
    Queue::fake();
    app(TenantRoleManager::class)->assign($this->user, $this->organization, TenantRole::Operator);

    livewire(DeviceControlDashboard::class, ['record' => $this->device->id])
        ->assertSuccessful()
        ->assertSee('Setup, Test & Control')
        ->assertSee('tenant/devices/portal-device-01/telemetry')
        ->callAction('simulatePublishing', data: [
            'count' => 3,
            'interval' => 0,
            'device_channel_id' => (string) $this->publishChannel->id,
        ])
        ->assertNotified('Simulation started');

    Queue::assertPushedTimes(SimulateDevicePublishingJob::class, 3);
    Queue::assertPushed(
        SimulateDevicePublishingJob::class,
        fn (SimulateDevicePublishingJob $job): bool => $job->deviceId === $this->device->id
            && $job->deviceChannelId === $this->publishChannel->id,
    );
});

it('rejects inactive profile versions during tenant device creation', function (): void {
    app(TenantRoleManager::class)->assign($this->user, $this->organization, TenantRole::TenantAdmin);
    $profile = DeviceProfile::factory()->create(['organization_id' => $this->organization->id]);
    $inactiveVersion = DeviceProfileVersion::factory()->mqtt()->create([
        'device_profile_id' => $profile->id,
        'status' => DeviceProfileVersion::STATUS_DRAFT,
    ]);
    $site = Entity::factory()->create(['organization_id' => $this->organization->id]);

    livewire(CreateDevice::class)
        ->fillForm([
            'name' => 'Inactive Profile Device',
            'entity_id' => $site->id,
            'device_profile_version_id' => $inactiveVersion->id,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasFormErrors(['device_profile_version_id']);

    expect(Device::query()->where('name', 'Inactive Profile Device')->exists())->toBeFalse();
});

it('redirects a newly created device to setup test and control', function (): void {
    app(TenantRoleManager::class)->assign($this->user, $this->organization, TenantRole::TenantAdmin);
    $site = Entity::factory()->create(['organization_id' => $this->organization->id]);

    $component = livewire(CreateDevice::class)
        ->fillForm([
            'name' => 'New Portal Device',
            'external_id' => 'new-portal-device',
            'entity_id' => $site->id,
            'device_profile_version_id' => $this->profileVersion->id,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $device = Device::query()->where('external_id', 'new-portal-device')->sole();

    $component->assertRedirect(DeviceResource::getUrl('control-dashboard', ['record' => $device]));
    expect($device->organization_id)->toBe($this->organization->id);
});

it('keeps credential management limited to tenant admins', function (): void {
    app(TenantRoleManager::class)->assign($this->user, $this->organization, TenantRole::Operator);

    livewire(DeviceControlDashboard::class, ['record' => $this->device->id])
        ->assertActionHidden('manageCredentials');

    app(TenantRoleManager::class)->assign($this->user, $this->organization, TenantRole::TenantAdmin);
    $this->user->unsetRelation('roles');

    livewire(DeviceControlDashboard::class, ['record' => $this->device->id])
        ->assertActionVisible('manageCredentials');
});

it('makes the private key bundle available for one download only', function (): void {
    app(TenantRoleManager::class)->assign($this->user, $this->organization, TenantRole::TenantAdmin);

    livewire(DeviceCredentials::class, ['record' => $this->device->id])
        ->set('credentialBundle', [
            'ca_certificate_pem' => 'test-ca',
            'device_certificate_pem' => 'test-certificate',
            'device_private_key_pem' => 'test-private-key',
            'has_active_certificate' => true,
        ])
        ->call('downloadCredentials')
        ->assertFileDownloaded()
        ->assertSet('credentialBundle', null);
});

it('rejects simulations for inactive devices and foreign publish channels', function (): void {
    Queue::fake();
    app(TenantRoleManager::class)->assign($this->user, $this->organization, TenantRole::Operator);
    $this->device->update(['is_active' => false]);

    livewire(DeviceControlDashboard::class, ['record' => $this->device->id])
        ->call('simulatePublishing', [
            'count' => 1,
            'interval' => 0,
            'device_channel_id' => (string) $this->publishChannel->id,
        ])
        ->assertNotified('Simulation unavailable');

    Queue::assertNothingPushed();

    $this->device->update(['is_active' => true]);
    $otherChannel = DeviceChannel::factory()->telemetry()->create();

    livewire(DeviceControlDashboard::class, ['record' => $this->device->id])
        ->call('simulatePublishing', [
            'count' => 1,
            'interval' => 0,
            'device_channel_id' => (string) $otherChannel->id,
        ])
        ->assertNotified('Publish channel not found');

    Queue::assertNothingPushed();
});

it('blocks viewers from the setup and test page', function (): void {
    app(TenantRoleManager::class)->assign($this->user, $this->organization, TenantRole::Viewer);

    livewire(DeviceControlDashboard::class, ['record' => $this->device->id])
        ->assertForbidden();
});
