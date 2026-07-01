<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Models\DeviceProfile;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use App\Filament\Admin\Resources\DeviceManagement\Devices\Pages\ListDevices;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = User::factory()->make(['is_super_admin' => true]);
    $this->actingAs($this->admin);
});

it('filters devices by device profile across versions', function (): void {
    $organization = Organization::factory()->create();

    $targetProfile = DeviceProfile::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Cold Room Sensor',
    ]);
    $targetVersionOne = DeviceProfileVersion::factory()->forProfile($targetProfile)->create(['version' => 1]);
    $targetVersionTwo = DeviceProfileVersion::factory()->forProfile($targetProfile)->create(['version' => 2]);

    $otherProfile = DeviceProfile::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Boiler Controller',
    ]);
    $otherVersion = DeviceProfileVersion::factory()->forProfile($otherProfile)->create(['version' => 1]);

    $deviceOnVersionOne = Device::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Cold Room 1',
        'device_profile_version_id' => $targetVersionOne->id,
    ]);
    $deviceOnVersionTwo = Device::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Cold Room 2',
        'device_profile_version_id' => $targetVersionTwo->id,
    ]);
    $otherDevice = Device::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Boiler 1',
        'device_profile_version_id' => $otherVersion->id,
    ]);

    livewire(ListDevices::class)
        ->filterTable('device_profile_id', $targetProfile->id)
        ->assertCanSeeTableRecords([$deviceOnVersionOne, $deviceOnVersionTwo])
        ->assertCanNotSeeTableRecords([$otherDevice]);
});

it('filters devices by dependent device profile version', function (): void {
    $organization = Organization::factory()->create();

    $profile = DeviceProfile::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Packaging Line Sensor',
    ]);
    $versionOne = DeviceProfileVersion::factory()->forProfile($profile)->create(['version' => 1]);
    $versionTwo = DeviceProfileVersion::factory()->forProfile($profile)->create(['version' => 2]);

    $deviceOnVersionOne = Device::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Line 1 Sensor',
        'device_profile_version_id' => $versionOne->id,
    ]);
    $deviceOnVersionTwo = Device::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Line 2 Sensor',
        'device_profile_version_id' => $versionTwo->id,
    ]);

    livewire(ListDevices::class)
        ->filterTable('device_profile_id', $profile->id)
        ->filterTable('device_profile_version_id', $versionTwo->id)
        ->assertCanSeeTableRecords([$deviceOnVersionTwo])
        ->assertCanNotSeeTableRecords([$deviceOnVersionOne]);
});

it('limits device profile version filter options to the selected device profile', function (): void {
    $organization = Organization::factory()->create();

    $profile = DeviceProfile::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Freezer Monitor',
    ]);
    $profileVersion = DeviceProfileVersion::factory()->forProfile($profile)->create(['version' => 3]);

    $otherProfile = DeviceProfile::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Pump Controller',
    ]);
    $otherProfileVersion = DeviceProfileVersion::factory()->forProfile($otherProfile)->create(['version' => 1]);

    $component = livewire(ListDevices::class)
        ->filterTable('device_profile_id', $profile->id);

    $options = $component
        ->instance()
        ->getTable()
        ->getFilter('device_profile_version_id')
        ->getOptions();

    expect($options)
        ->toHaveKey($profileVersion->id)
        ->not->toHaveKey($otherProfileVersion->id);
});
