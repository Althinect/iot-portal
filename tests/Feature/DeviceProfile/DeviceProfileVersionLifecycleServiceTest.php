<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Enums\ChannelLinkType;
use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\DeviceChannelLink;
use App\Domain\DeviceProfile\Models\DeviceProfile;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Models\ProfileDerivedParameterDefinition;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use App\Domain\DeviceProfile\Services\DeviceProfileVersionLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('clones a version as draft and activates it without moving existing devices', function (): void {
    $profile = DeviceProfile::factory()->global()->create([
        'key' => 'standard_meter',
        'name' => 'Standard Meter',
    ]);

    $activeVersion = DeviceProfileVersion::factory()->forProfile($profile)->active()->mqtt()->create([
        'version' => 1,
    ]);

    $commandChannel = DeviceChannel::factory()->command()->create([
        'device_profile_version_id' => $activeVersion->id,
        'key' => 'command',
        'label' => 'Command',
    ]);
    $stateChannel = DeviceChannel::factory()->publish()->create([
        'device_profile_version_id' => $activeVersion->id,
        'key' => 'state',
        'label' => 'State',
    ]);
    ProfileParameterDefinition::factory()->create([
        'device_channel_id' => $stateChannel->id,
        'key' => 'voltage',
        'label' => 'Voltage',
        'json_path' => 'voltage',
        'type' => ParameterDataType::Decimal,
    ]);
    ProfileDerivedParameterDefinition::factory()->create([
        'device_profile_version_id' => $activeVersion->id,
        'key' => 'voltage_double',
        'dependencies' => ['voltage'],
        'expression' => ['*' => [['var' => 'voltage'], 2]],
    ]);
    DeviceChannelLink::query()->create([
        'from_device_channel_id' => $commandChannel->id,
        'to_device_channel_id' => $stateChannel->id,
        'link_type' => ChannelLinkType::StateFeedback->value,
    ]);

    $device = Device::factory()->create([
        'device_profile_version_id' => $activeVersion->id,
    ]);

    $service = app(DeviceProfileVersionLifecycleService::class);
    $draft = $service->cloneAsDraft($activeVersion);

    expect($draft->status)->toBe(DeviceProfileVersion::STATUS_DRAFT)
        ->and($draft->version)->toBe(2)
        ->and($draft->channels()->count())->toBe(2)
        ->and($draft->derivedParameters()->count())->toBe(1)
        ->and($draft->channelLinks()->count())->toBe(1);

    $service->activate($draft);

    expect($activeVersion->fresh()->status)->toBe(DeviceProfileVersion::STATUS_SUPERSEDED)
        ->and($draft->fresh()->status)->toBe(DeviceProfileVersion::STATUS_ACTIVE)
        ->and($device->fresh()->device_profile_version_id)->toBe($activeVersion->id);
});
