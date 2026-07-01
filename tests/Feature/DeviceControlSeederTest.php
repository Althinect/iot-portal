<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Enums\ChannelDirection;
use App\Domain\DeviceProfile\Models\DeviceProfile;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use Database\Seeders\DeviceControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds a dimmable light device with state and control topics', function (): void {
    $this->seed(DeviceControlSeeder::class);

    $profile = DeviceProfile::where('key', 'dimmable_light')->first();
    expect($profile)->not->toBeNull();

    $version = $profile?->versions()->where('status', 'active')->first();
    expect($version)->not->toBeNull();

    $controlChannel = $version?->channels()
        ->where('key', 'lighting_control')
        ->first();

    expect($controlChannel)->not->toBeNull()
        ->and($controlChannel?->direction)->toBe(ChannelDirection::Subscribe)
        ->and($controlChannel?->address)->toBe('lighting/control');

    $parameter = ProfileParameterDefinition::where('device_channel_id', $controlChannel->id)
        ->where('key', 'brightness')
        ->first();

    expect($parameter)->not->toBeNull()
        ->and($parameter?->validation_rules)->toMatchArray(['min' => 0, 'max' => 100]);

    $device = Device::where('device_profile_version_id', $version->id)
        ->where('external_id', 'dimmable-light-01')
        ->first();

    expect($device)->not->toBeNull();
});

it('seeds the energy meter device required by automation workflows', function (): void {
    $this->seed(DeviceControlSeeder::class);

    $profile = DeviceProfile::where('key', 'energy_meter')->first();
    expect($profile)->not->toBeNull();

    $version = $profile?->versions()->where('status', 'active')->first();
    expect($version)->not->toBeNull();

    $energyMeter = Device::where('device_profile_version_id', $version->id)
        ->where('external_id', 'main-energy-meter-01')
        ->first();

    expect($energyMeter)->not->toBeNull();
});

it('seeds the single-phase energy meter device', function (): void {
    $this->seed(DeviceControlSeeder::class);

    $profile = DeviceProfile::where('key', 'single_phase_energy_meter')->first();
    expect($profile)->not->toBeNull();

    $version = $profile?->versions()->where('status', 'active')->first();
    expect($version)->not->toBeNull();

    $singlePhaseEnergyMeter = Device::where('device_profile_version_id', $version->id)
        ->where('external_id', 'single-phase-energy-meter-01')
        ->first();

    expect($singlePhaseEnergyMeter)->not->toBeNull();
});
