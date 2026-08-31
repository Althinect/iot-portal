<?php

declare(strict_types=1);

use App\Domain\Authorization\Enums\TenantRole;
use App\Domain\Authorization\Services\TenantRoleManager;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Services\TenantDeviceLifecycleManager;
use App\Domain\DeviceProfile\Models\DeviceProfile;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\Shared\Models\Entity;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use App\Filament\Portal\Resources\DeviceManagement\Devices\DeviceResource;
use App\Filament\Portal\Resources\DeviceManagement\Devices\Pages\CreateDevice;
use App\Filament\Portal\Resources\DeviceManagement\Devices\Pages\DeviceCredentials;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->organization = Organization::factory()->create();
    $this->otherOrganization = Organization::factory()->create();
    $this->tenantAdmin = User::factory()->create();
    $this->tenantAdmin->organizations()->attach($this->organization);
    app(TenantRoleManager::class)->assign(
        $this->tenantAdmin,
        $this->organization,
        TenantRole::TenantAdmin,
    );

    $this->globalProfile = DeviceProfile::factory()->global()->create();
    $this->profileVersion = DeviceProfileVersion::factory()->active()->mqtt()->create([
        'device_profile_id' => $this->globalProfile->id,
    ]);
    $this->site = Entity::factory()->create(['organization_id' => $this->organization->id]);

    $this->actingAs($this->tenantAdmin);
    Filament::setCurrentPanel('portal');
    Filament::setTenant($this->organization);
    Filament::bootCurrentPanel();
});

afterEach(function (): void {
    setPermissionsTeamId(null);
});

it('provisions a device into the active tenant and redirects to one-time credentials', function (): void {
    livewire(CreateDevice::class)
        ->fillForm([
            'name' => 'Boiler Sensor 01',
            'external_id' => 'boiler-sensor-01',
            'entity_id' => $this->site->id,
            'device_profile_version_id' => $this->profileVersion->id,
            'is_active' => true,
            'metadata' => ['asset_tag' => 'A-100'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $device = Device::query()->where('external_id', 'boiler-sensor-01')->sole();

    expect($device->organization_id)->toBe($this->organization->id)
        ->and($device->entity_id)->toBe($this->site->id)
        ->and($device->device_profile_version_id)->toBe($this->profileVersion->id)
        ->and(DeviceResource::canCreate())->toBeTrue();
});

it('rejects cross-tenant sites and private profiles during provisioning', function (): void {
    $otherSite = Entity::factory()->create(['organization_id' => $this->otherOrganization->id]);
    $otherProfile = DeviceProfile::factory()->create(['organization_id' => $this->otherOrganization->id]);
    $otherVersion = DeviceProfileVersion::factory()->active()->mqtt()->create([
        'device_profile_id' => $otherProfile->id,
    ]);

    livewire(CreateDevice::class)
        ->fillForm([
            'name' => 'Invalid Device',
            'entity_id' => $otherSite->id,
            'device_profile_version_id' => $otherVersion->id,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasFormErrors(['device_profile_version_id']);

    expect(Device::query()->where('name', 'Invalid Device')->exists())->toBeFalse();
});

it('decommissions and reactivates devices without hard deletion', function (): void {
    $device = Device::factory()->create([
        'organization_id' => $this->organization->id,
        'device_profile_version_id' => $this->profileVersion->id,
    ]);
    $lifecycleManager = app(TenantDeviceLifecycleManager::class);

    $lifecycleManager->decommission($device, $this->tenantAdmin);

    $device->refresh();
    expect($device->trashed())->toBeTrue()
        ->and($device->is_active)->toBeFalse()
        ->and(Device::withTrashed()->whereKey($device->id)->exists())->toBeTrue();

    $lifecycleManager->reactivate($device, $this->tenantAdmin);

    expect($device->refresh()->trashed())->toBeFalse()
        ->and($device->is_active)->toBeTrue();
});

it('keeps provisioning and credential management unavailable to operators', function (): void {
    $operator = User::factory()->create();
    $operator->organizations()->attach($this->organization);
    app(TenantRoleManager::class)->assign($operator, $this->organization, TenantRole::Operator);
    $device = Device::factory()->create([
        'organization_id' => $this->organization->id,
        'device_profile_version_id' => $this->profileVersion->id,
    ]);
    $this->actingAs($operator);
    Filament::setTenant($this->organization);

    expect($operator->can('control', $device))->toBeTrue()
        ->and($operator->can('provision', Device::class))->toBeFalse()
        ->and($operator->can('manageCredentials', $device))->toBeFalse()
        ->and(fn () => app(TenantDeviceLifecycleManager::class)->decommission($device, $operator))
        ->toThrow(AuthorizationException::class);

    livewire(DeviceCredentials::class, ['record' => $device->id])->assertForbidden();
});
