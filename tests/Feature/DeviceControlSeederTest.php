<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Enums\ChannelDirection;
use App\Domain\DeviceProfile\Enums\ChannelLinkType;
use App\Domain\DeviceProfile\Enums\ChannelPurpose;
use App\Domain\DeviceProfile\Models\DeviceChannelLink;
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

it('seeds the Waveshare audio alarm control and state contract', function (): void {
    $this->seed(DeviceControlSeeder::class);

    $profile = DeviceProfile::query()->where('key', 'waveshare_audio_alarm')->first();
    $version = $profile?->versions()->where('status', 'active')->first();
    $commandChannel = $version?->channels()->where('key', 'control')->first();
    $stateChannel = $version?->channels()->where('key', 'state')->first();

    expect($profile)->not->toBeNull()
        ->and($version)->not->toBeNull()
        ->and($version?->protocol_config?->getBaseTopic())->toBe('devices')
        ->and($version?->protocol_config?->username)->toBe('device-client')
        ->and($version?->protocol_config?->password)->toBeNull()
        ->and($commandChannel)->not->toBeNull()
        ->and($commandChannel?->direction)->toBe(ChannelDirection::Subscribe)
        ->and($commandChannel?->purpose)->toBe(ChannelPurpose::Command)
        ->and($commandChannel?->address)->toBe('control')
        ->and($commandChannel?->qos)->toBe(1)
        ->and($commandChannel?->retain)->toBeFalse()
        ->and($stateChannel)->not->toBeNull()
        ->and($stateChannel?->direction)->toBe(ChannelDirection::Publish)
        ->and($stateChannel?->purpose)->toBe(ChannelPurpose::State)
        ->and($stateChannel?->address)->toBe('state')
        ->and($stateChannel?->qos)->toBe(1)
        ->and($stateChannel?->retain)->toBeTrue();

    $commandParameter = ProfileParameterDefinition::query()
        ->where('device_channel_id', $commandChannel?->id)
        ->where('key', 'alarm_on')
        ->first();
    $stateParameter = ProfileParameterDefinition::query()
        ->where('device_channel_id', $stateChannel?->id)
        ->where('key', 'alarm_on')
        ->first();
    $feedbackLink = DeviceChannelLink::query()
        ->where('from_device_channel_id', $commandChannel?->id)
        ->where('to_device_channel_id', $stateChannel?->id)
        ->first();
    $device = Device::query()
        ->where('device_profile_version_id', $version?->id)
        ->where('external_id', 'alarm-demo-01')
        ->first();

    expect($commandParameter)->not->toBeNull()
        ->and($commandParameter?->json_path)->toBe('alarm_on')
        ->and($commandParameter?->default_value)->toBeFalse()
        ->and($stateParameter)->not->toBeNull()
        ->and($stateParameter?->json_path)->toBe('alarm_on')
        ->and($stateParameter?->default_value)->toBeFalse()
        ->and($feedbackLink)->not->toBeNull()
        ->and($feedbackLink?->link_type)->toBe(ChannelLinkType::StateFeedback)
        ->and($device)->not->toBeNull();
});
