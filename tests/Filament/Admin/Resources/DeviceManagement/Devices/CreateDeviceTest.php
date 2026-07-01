<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Models\DeviceProfile;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use App\Filament\Admin\Resources\DeviceManagement\Devices\Pages\CreateDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

function createGenericDeviceFormContext(): array
{
    $organization = Organization::factory()->create();
    $profile = DeviceProfile::factory()->global()->create([
        'name' => 'Standard Aggregate Device',
        'key' => 'standard_aggregate_device',
    ]);
    $activeProfileVersion = DeviceProfileVersion::factory()->forProfile($profile)->active()->mqtt()->create([
        'version' => 1,
    ]);

    return compact('organization', 'profile', 'activeProfileVersion');
}

function createStenterStandardFormContext(): array
{
    $organization = Organization::factory()->create();
    $profile = DeviceProfile::factory()->global()->create([
        'name' => 'Stenter Line',
        'key' => 'stenter_line',
    ]);
    $activeProfileVersion = DeviceProfileVersion::factory()->forProfile($profile)->active()->mqtt()->create([
        'version' => 1,
        'virtual_standard_profile' => [
            'label' => 'Stenter Standard',
            'description' => 'Managed stenter profile',
            'shift_schedule' => [
                'id' => 'teejay_stenter_06_00',
                'label' => 'Teejay 06:00 Shift',
            ],
            'sources' => [
                'status' => [
                    'label' => 'Status',
                    'required' => true,
                    'allowed_device_profile_keys' => ['status'],
                ],
                'energy' => [
                    'label' => 'Energy',
                    'required' => true,
                    'allowed_device_profile_keys' => ['energy_meter'],
                ],
                'length' => [
                    'label' => 'Length',
                    'required' => true,
                    'allowed_device_profile_keys' => ['fabric_length_counter'],
                ],
            ],
        ],
    ]);

    return compact('organization', 'profile', 'activeProfileVersion');
}

function createSourceDevice(Organization $organization, string $profileKey, string $profileName, string $deviceName): Device
{
    $profile = DeviceProfile::query()
        ->whereNull('organization_id')
        ->where('key', $profileKey)
        ->first();

    if (! $profile instanceof DeviceProfile) {
        $profile = DeviceProfile::factory()->global()->create([
            'key' => $profileKey,
            'name' => $profileName,
        ]);
    }

    $activeProfileVersion = DeviceProfileVersion::query()->firstOrCreate(
        [
            'device_profile_id' => $profile->id,
            'version' => 1,
        ],
        [
            'status' => 'active',
            'protocol' => 'mqtt',
            'protocol_config' => DeviceProfileVersion::factory()->mqtt()->make()->protocol_config,
        ],
    );

    if ($activeProfileVersion->status !== 'active') {
        $activeProfileVersion->update(['status' => 'active']);
    }

    return Device::factory()->create([
        'organization_id' => $organization->id,
        'device_profile_version_id' => $activeProfileVersion->id,
        'name' => $deviceName,
    ]);
}

beforeEach(function (): void {
    Auth::login(User::factory()->create(['is_super_admin' => true]));
});

it('can render the create device page', function (): void {
    livewire(CreateDevice::class)
        ->assertSuccessful();
});

it('can create a virtual device and attach physical source devices by purpose', function (): void {
    ['organization' => $organization, 'activeProfileVersion' => $activeProfileVersion] = createGenericDeviceFormContext();

    $statusDevice = Device::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Status Sensor',
    ]);
    $energyDevice = Device::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Energy Meter',
    ]);

    livewire(CreateDevice::class)
        ->fillForm([
            'name' => 'Stenter 01 Standard',
            'organization_id' => $organization->id,
            'device_profile_version_id' => $activeProfileVersion->id,
            'is_virtual' => true,
            'virtual_device_links' => [
                [
                    'purpose' => 'status',
                    'source_device_id' => $statusDevice->id,
                ],
                [
                    'purpose' => 'energy',
                    'source_device_id' => $energyDevice->id,
                ],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $virtualDevice = Device::query()
        ->where('name', 'Stenter 01 Standard')
        ->first();

    expect($virtualDevice)->not->toBeNull()
        ->and($virtualDevice?->isVirtual())->toBeTrue()
        ->and($virtualDevice?->parent_device_id)->toBeNull();

    $this->assertDatabaseHas('virtual_device_links', [
        'virtual_device_id' => $virtualDevice?->id,
        'source_device_id' => $statusDevice->id,
        'purpose' => 'status',
        'sequence' => 1,
    ]);

    $this->assertDatabaseHas('virtual_device_links', [
        'virtual_device_id' => $virtualDevice?->id,
        'source_device_id' => $energyDevice->id,
        'purpose' => 'energy',
        'sequence' => 2,
    ]);
});

it('rejects source devices from another organization when creating a virtual device', function (): void {
    ['organization' => $organization, 'activeProfileVersion' => $activeProfileVersion] = createGenericDeviceFormContext();

    $outsideDevice = Device::factory()->create();

    livewire(CreateDevice::class)
        ->fillForm([
            'name' => 'Cross Org Standard',
            'organization_id' => $organization->id,
            'device_profile_version_id' => $activeProfileVersion->id,
            'is_virtual' => true,
            'virtual_device_links' => [[
                'purpose' => 'status',
                'source_device_id' => $outsideDevice->id,
            ]],
        ])
        ->call('create')
        ->assertHasFormErrors(['virtual_device_links.0.source_device_id']);
});

it('can still create a physical device with a parent hub', function (): void {
    ['organization' => $organization, 'activeProfileVersion' => $activeProfileVersion] = createGenericDeviceFormContext();

    $hub = Device::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Main Hub',
        'parent_device_id' => null,
    ]);

    livewire(CreateDevice::class)
        ->fillForm([
            'name' => 'Pressure Sensor',
            'organization_id' => $organization->id,
            'device_profile_version_id' => $activeProfileVersion->id,
            'is_virtual' => false,
            'parent_device_id' => $hub->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('devices', [
        'name' => 'Pressure Sensor',
        'organization_id' => $organization->id,
        'parent_device_id' => $hub->id,
        'is_virtual' => false,
    ]);
});

it('stores managed stenter standard profile metadata on create', function (): void {
    ['organization' => $organization, 'activeProfileVersion' => $activeProfileVersion] = createStenterStandardFormContext();

    $statusDevice = createSourceDevice($organization, 'status', 'Status', 'Status Sensor');
    $energyDevice = createSourceDevice($organization, 'energy_meter', 'Energy Meter', 'Energy Meter');
    $lengthDevice = createSourceDevice($organization, 'fabric_length_counter', 'Fabric Length Counter', 'Length Counter');

    livewire(CreateDevice::class)
        ->fillForm([
            'name' => 'Stenter 01 Standard',
            'organization_id' => $organization->id,
            'device_profile_version_id' => $activeProfileVersion->id,
            'is_virtual' => true,
            'virtual_device_links' => [
                [
                    'purpose' => 'status',
                    'source_device_id' => $statusDevice->id,
                ],
                [
                    'purpose' => 'energy',
                    'source_device_id' => $energyDevice->id,
                ],
                [
                    'purpose' => 'length',
                    'source_device_id' => $lengthDevice->id,
                ],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $virtualDevice = Device::query()
        ->where('name', 'Stenter 01 Standard')
        ->first();

    expect($virtualDevice)->not->toBeNull()
        ->and(data_get($virtualDevice?->metadata, 'virtual_standard_profile_key'))->toBe('stenter_line')
        ->and(data_get($virtualDevice?->metadata, 'virtual_standard_shift_schedule_id'))->toBe('teejay_stenter_06_00')
        ->and(data_get($virtualDevice?->metadata, 'virtual_standard_source_purposes'))->toEqualCanonicalizing(['status', 'energy', 'length']);
});

it('rejects missing required stenter sources when creating a standard profile device', function (): void {
    ['organization' => $organization, 'activeProfileVersion' => $activeProfileVersion] = createStenterStandardFormContext();

    $statusDevice = createSourceDevice($organization, 'status', 'Status', 'Status Sensor');
    $energyDevice = createSourceDevice($organization, 'energy_meter', 'Energy Meter', 'Energy Meter');

    livewire(CreateDevice::class)
        ->fillForm([
            'name' => 'Incomplete Stenter Standard',
            'organization_id' => $organization->id,
            'device_profile_version_id' => $activeProfileVersion->id,
            'is_virtual' => true,
            'virtual_device_links' => [
                [
                    'purpose' => 'status',
                    'source_device_id' => $statusDevice->id,
                ],
                [
                    'purpose' => 'energy',
                    'source_device_id' => $energyDevice->id,
                ],
            ],
        ])
        ->call('create');

    expect(Device::query()->where('name', 'Incomplete Stenter Standard')->exists())->toBeFalse();
});

it('rejects source devices that do not match the stenter profile source type rules', function (): void {
    ['organization' => $organization, 'activeProfileVersion' => $activeProfileVersion] = createStenterStandardFormContext();

    $statusDevice = createSourceDevice($organization, 'status', 'Status', 'Status Sensor');
    $energyDevice = createSourceDevice($organization, 'energy_meter', 'Energy Meter', 'Energy Meter');
    $wrongLengthDevice = createSourceDevice($organization, 'pressure_sensor', 'Pressure Sensor', 'Pressure Device');

    livewire(CreateDevice::class)
        ->fillForm([
            'name' => 'Invalid Stenter Standard',
            'organization_id' => $organization->id,
            'device_profile_version_id' => $activeProfileVersion->id,
            'is_virtual' => true,
            'virtual_device_links' => [
                [
                    'purpose' => 'status',
                    'source_device_id' => $statusDevice->id,
                ],
                [
                    'purpose' => 'energy',
                    'source_device_id' => $energyDevice->id,
                ],
                [
                    'purpose' => 'length',
                    'source_device_id' => $wrongLengthDevice->id,
                ],
            ],
        ])
        ->call('create');

    expect(Device::query()->where('name', 'Invalid Stenter Standard')->exists())->toBeFalse();
});
