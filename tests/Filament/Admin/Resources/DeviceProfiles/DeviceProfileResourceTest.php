<?php

declare(strict_types=1);

use App\Domain\DeviceProfile\Enums\ChannelDirection;
use App\Domain\DeviceProfile\Enums\ChannelPurpose;
use App\Domain\DeviceProfile\Enums\ChannelTransport;
use App\Domain\DeviceProfile\Enums\ParameterCategory;
use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Enums\Protocol;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\DeviceProfile;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use App\Domain\Shared\Models\User;
use App\Filament\Admin\Resources\DeviceProfiles\Pages\CreateDeviceProfile;
use App\Filament\Admin\Resources\DeviceProfiles\Pages\ListDeviceProfiles;
use App\Filament\Admin\Resources\DeviceProfileVersions\Pages\EditDeviceProfileVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Auth::login(User::factory()->create(['is_super_admin' => true]));
});

it('renders the device types and profiles table', function (): void {
    $profile = DeviceProfile::factory()->global()->create([
        'key' => 'energy_meter',
        'name' => 'Energy Meter',
    ]);

    $version = DeviceProfileVersion::factory()->forProfile($profile)->active()->mqtt()->create();
    $channel = DeviceChannel::factory()->telemetry()->create([
        'device_profile_version_id' => $version->id,
        'key' => 'telemetry',
        'label' => 'Telemetry',
    ]);
    ProfileParameterDefinition::factory()->create([
        'device_channel_id' => $channel->id,
        'key' => 'voltage',
        'label' => 'Voltage',
    ]);

    livewire(ListDeviceProfiles::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$profile]);
});

it('creates a profile with an initial draft version and starter channel', function (): void {
    livewire(CreateDeviceProfile::class)
        ->fillForm([
            'name' => 'Energy Meter',
            'key' => 'energy_meter',
            'organization_id' => null,
            'tags' => ['family' => 'energy'],
            'protocol' => Protocol::Mqtt->value,
            'mqtt_broker_host' => 'broker.example.test',
            'mqtt_broker_port' => 1883,
            'mqtt_base_topic' => 'energy',
            'mqtt_use_tls' => false,
            'notes' => 'Initial energy meter draft.',
            'starter_channels' => [
                [
                    'key' => 'telemetry',
                    'label' => 'Telemetry',
                    'direction' => ChannelDirection::Publish->value,
                    'purpose' => ChannelPurpose::Telemetry->value,
                    'transport' => ChannelTransport::Mqtt->value,
                    'address' => 'telemetry',
                    'qos' => 1,
                    'retain' => false,
                    'parameters' => [
                        [
                            'key' => 'voltage',
                            'label' => 'Voltage',
                            'json_path' => 'voltage',
                            'type' => ParameterDataType::Decimal->value,
                            'category' => ParameterCategory::Measurement->value,
                            'unit' => 'V',
                            'required' => true,
                            'is_critical' => true,
                        ],
                    ],
                ],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $profile = DeviceProfile::query()->where('key', 'energy_meter')->firstOrFail();
    $version = $profile->versions()->firstOrFail();
    $channel = $version->channels()->firstOrFail();

    expect($version->status)->toBe(DeviceProfileVersion::STATUS_DRAFT)
        ->and($version->version)->toBe(1)
        ->and($version->protocol)->toBe(Protocol::Mqtt)
        ->and($version->protocol_config?->getBaseTopic())->toBe('device')
        ->and($channel->key)->toBe('telemetry');

    $this->assertDatabaseHas('profile_parameter_definitions', [
        'device_channel_id' => $channel->id,
        'key' => 'voltage',
        'json_path' => 'voltage',
    ]);
});

it('renders the version editor and allows notes on an active contract', function (): void {
    $version = DeviceProfileVersion::factory()->active()->mqtt()->create([
        'notes' => 'Initial active contract.',
    ]);
    DeviceChannel::factory()->telemetry()->create([
        'device_profile_version_id' => $version->id,
    ]);

    livewire(EditDeviceProfileVersion::class, ['record' => $version->id])
        ->assertSuccessful()
        ->fillForm([
            'notes' => 'Keep active contract stable.',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($version->fresh()->notes)->toBe('Keep active contract stable.');
});
