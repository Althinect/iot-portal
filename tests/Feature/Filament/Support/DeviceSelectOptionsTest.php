<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Models\DeviceProfile;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\Shared\Models\Organization;
use App\Support\DeviceSelectOptions;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('groups device options by device profile and resolves nested labels', function (): void {
    $organization = Organization::factory()->create();
    $energyMeterProfile = DeviceProfile::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Energy Meter',
        'key' => 'energy_meter_select_options',
    ]);
    $steamMeterProfile = DeviceProfile::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Steam Meter',
        'key' => 'steam_meter_select_options',
    ]);
    $energyMeterVersion = DeviceProfileVersion::factory()->forProfile($energyMeterProfile)->active()->create();
    $steamMeterVersion = DeviceProfileVersion::factory()->forProfile($steamMeterProfile)->active()->create();

    $energyMeter = Device::factory()->create([
        'organization_id' => $organization->id,
        'device_profile_version_id' => $energyMeterVersion->id,
        'name' => 'Main Compressor Meter',
        'external_id' => 'EM-100',
    ]);
    $steamMeter = Device::factory()->create([
        'organization_id' => $organization->id,
        'device_profile_version_id' => $steamMeterVersion->id,
        'name' => 'Steam Header',
        'external_id' => 'SM-200',
    ]);

    $options = DeviceSelectOptions::groupedByType(
        Device::query()->where('organization_id', $organization->id),
    );

    expect($options)
        ->toHaveKey('Energy Meter')
        ->toHaveKey('Steam Meter')
        ->and($options['Energy Meter'])
        ->toMatchArray([$energyMeter->id => 'Main Compressor Meter (EM-100)'])
        ->and($options['Steam Meter'])
        ->toMatchArray([$steamMeter->id => 'Steam Header (SM-200)'])
        ->and(DeviceSelectOptions::findLabel($options, $steamMeter->id))
        ->toBe('Steam Header (SM-200)');
});

it('can collapse a single device profile group into flat options and use uuid fallback labels', function (): void {
    $organization = Organization::factory()->create();
    $statusProfile = DeviceProfile::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Status Sensor',
        'key' => 'status_sensor_select_options',
    ]);
    $statusVersion = DeviceProfileVersion::factory()->forProfile($statusProfile)->active()->create();

    $statusDevice = Device::factory()->create([
        'organization_id' => $organization->id,
        'device_profile_version_id' => $statusVersion->id,
        'name' => 'Line Status Sensor',
        'external_id' => null,
    ]);

    $options = DeviceSelectOptions::groupedByType(
        Device::query()->where('organization_id', $organization->id),
        useUuidFallback: true,
        collapseSingleGroup: true,
    );

    expect($options)
        ->toMatchArray([
            $statusDevice->id => "Line Status Sensor ({$statusDevice->uuid})",
        ])
        ->and(DeviceSelectOptions::findLabel($options, $statusDevice->id))
        ->toBe("Line Status Sensor ({$statusDevice->uuid})");
});
