<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Models\VirtualDeviceLink;
use App\Domain\DeviceProfile\Models\DeviceProfile;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use App\Filament\Admin\Resources\DeviceManagement\Devices\Pages\EditDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

function editGenericDeviceFormContext(): array
{
    $organization = Organization::factory()->create();
    $profile = DeviceProfile::factory()->global()->create([
        'name' => 'Standard Aggregate Device',
        'key' => 'standard_aggregate_device_edit',
    ]);
    $activeProfileVersion = DeviceProfileVersion::factory()->forProfile($profile)->active()->mqtt()->create([
        'version' => 1,
    ]);

    return compact('organization', 'profile', 'activeProfileVersion');
}

function editStenterStandardFormContext(): array
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

function editSourceDevice(Organization $organization, string $profileKey, string $profileName, string $deviceName): Device
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

it('can update a virtual device and resync its source devices', function (): void {
    ['organization' => $organization, 'activeProfileVersion' => $activeProfileVersion] = editStenterStandardFormContext();

    $statusDevice = editSourceDevice($organization, 'status', 'Status', 'Status Sensor');
    $energyDevice = editSourceDevice($organization, 'energy_meter', 'Energy Meter', 'Energy Meter');
    $oldLengthDevice = editSourceDevice($organization, 'fabric_length_counter', 'Fabric Length Counter', 'Length Counter 01');
    $newLengthDevice = editSourceDevice($organization, 'fabric_length_counter', 'Fabric Length Counter', 'Length Counter 02');

    $virtualDevice = Device::factory()->virtual()->create([
        'organization_id' => $organization->id,
        'device_profile_version_id' => $activeProfileVersion->id,
        'name' => 'Stenter Standard',
    ]);

    $statusLink = VirtualDeviceLink::factory()->create([
        'virtual_device_id' => $virtualDevice->id,
        'source_device_id' => $statusDevice->id,
        'purpose' => 'status',
        'sequence' => 1,
    ]);
    $energyLink = VirtualDeviceLink::factory()->create([
        'virtual_device_id' => $virtualDevice->id,
        'source_device_id' => $energyDevice->id,
        'purpose' => 'energy',
        'sequence' => 2,
    ]);
    $lengthLink = VirtualDeviceLink::factory()->create([
        'virtual_device_id' => $virtualDevice->id,
        'source_device_id' => $oldLengthDevice->id,
        'purpose' => 'length',
        'sequence' => 3,
    ]);

    livewire(EditDevice::class, ['record' => $virtualDevice->getRouteKey()])
        ->fillForm([
            'name' => 'Stenter Standard v2',
            'organization_id' => $organization->id,
            'device_profile_version_id' => $activeProfileVersion->id,
            'is_virtual' => true,
            'virtual_device_links' => [
                [
                    'id' => $statusLink->id,
                    'purpose' => 'status',
                    'source_device_id' => $statusDevice->id,
                ],
                [
                    'id' => $energyLink->id,
                    'purpose' => 'energy',
                    'source_device_id' => $energyDevice->id,
                ],
                [
                    'purpose' => 'length',
                    'source_device_id' => $newLengthDevice->id,
                ],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('devices', [
        'id' => $virtualDevice->id,
        'name' => 'Stenter Standard v2',
        'is_virtual' => true,
    ]);

    $this->assertDatabaseHas('virtual_device_links', [
        'id' => $statusLink->id,
        'virtual_device_id' => $virtualDevice->id,
        'source_device_id' => $statusDevice->id,
        'purpose' => 'status',
        'sequence' => 1,
    ]);

    $this->assertDatabaseHas('virtual_device_links', [
        'virtual_device_id' => $virtualDevice->id,
        'source_device_id' => $newLengthDevice->id,
        'purpose' => 'length',
        'sequence' => 3,
    ]);

    $this->assertDatabaseMissing('virtual_device_links', [
        'id' => $lengthLink->id,
    ]);

    expect(Device::query()->find($virtualDevice->id)?->metadata)
        ->toMatchArray([
            'virtual_standard_profile_key' => 'stenter_line',
            'virtual_standard_shift_schedule_id' => 'teejay_stenter_06_00',
        ]);
});

it('clears virtual source links when a device is switched back to physical', function (): void {
    ['organization' => $organization, 'activeProfileVersion' => $activeProfileVersion] = editGenericDeviceFormContext();

    $sourceDevice = Device::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Status Sensor',
    ]);
    $hub = Device::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Main Hub',
        'parent_device_id' => null,
    ]);

    $virtualDevice = Device::factory()->virtual()->create([
        'organization_id' => $organization->id,
        'device_profile_version_id' => $activeProfileVersion->id,
        'name' => 'Convertible Device',
    ]);

    $link = VirtualDeviceLink::factory()->create([
        'virtual_device_id' => $virtualDevice->id,
        'source_device_id' => $sourceDevice->id,
        'purpose' => 'status',
        'sequence' => 1,
    ]);

    livewire(EditDevice::class, ['record' => $virtualDevice->getRouteKey()])
        ->fillForm([
            'name' => 'Convertible Device',
            'organization_id' => $organization->id,
            'device_profile_version_id' => $activeProfileVersion->id,
            'is_virtual' => false,
            'parent_device_id' => $hub->id,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('devices', [
        'id' => $virtualDevice->id,
        'is_virtual' => false,
        'parent_device_id' => $hub->id,
    ]);

    $this->assertDatabaseMissing('virtual_device_links', [
        'id' => $link->id,
    ]);
});
