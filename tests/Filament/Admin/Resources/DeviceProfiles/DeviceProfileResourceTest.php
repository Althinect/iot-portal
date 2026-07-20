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
use App\Filament\Admin\Resources\DeviceProfileVersions\RelationManagers\ChannelsRelationManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Auth::login(User::factory()->create(['is_super_admin' => true]));
});

it('renders device types profiles table', function (): void {
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

it('creates mqtt profile with mutation expression on starter channel parameter', function (): void {
    livewire(CreateDeviceProfile::class)
        ->fillForm([
            'name' => 'Energy Meter',
            'key' => 'energy_meter',
            'organization_id' => null,
            'protocol' => Protocol::Mqtt->value,
        ])
        ->fillForm([
            'mqtt_broker_host' => 'mqtt.example.test',
            'mqtt_broker_port' => 1883,
            'mqtt_base_topic' => 'energy',
            'mqtt_use_tls' => true,
            'notes' => 'Initial energy meter draft.',
            'starter_channels' => [
                [
                    'key' => 'telemetry',
                    'label' => 'Telemetry',
                    'direction' => ChannelDirection::Publish->value,
                    'purpose' => ChannelPurpose::Telemetry->value,
                    'transport' => ChannelTransport::Http->value,
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
                            'mutation_expression' => '{"*":[{"var":"val"},100]}',
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
    $parameter = $channel->parameters()->firstOrFail();

    expect($version->status)->toBe(DeviceProfileVersion::STATUS_DRAFT)
        ->and($version->version)->toBe(1)
        ->and($version->protocol)->toBe(Protocol::Mqtt)
        ->and($version->protocol_config?->getBaseTopic())->toBe('energy')
        ->and($channel->key)->toBe('telemetry')
        ->and($channel->transport)->toBe(ChannelTransport::Mqtt)
        ->and($parameter->mutation_expression)->toBe(['*' => [['var' => 'val'], 100]]);
});

it('creates http profile as one forced telemetry webhook channel', function (): void {
    livewire(CreateDeviceProfile::class)
        ->fillForm([
            'name' => 'HTTP Energy Meter',
            'key' => 'http_energy_meter',
            'organization_id' => null,
            'protocol' => Protocol::Http->value,
        ])
        ->fillForm([
            'http_base_url' => 'https://devices.example.test',
            'http_telemetry_endpoint' => 'measurements',
            'http_method' => 'PATCH',
            'notes' => 'Initial HTTP energy meter draft.',
            'starter_channels' => [
                [
                    'key' => 'command',
                    'label' => 'Command',
                    'direction' => ChannelDirection::Subscribe->value,
                    'purpose' => ChannelPurpose::Command->value,
                    'transport' => ChannelTransport::Mqtt->value,
                    'address' => 'commands',
                    'http_method' => 'GET',
                    'qos' => 2,
                    'retain' => true,
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
                            'mutation_expression' => '',
                        ],
                    ],
                ],
                [
                    'key' => 'extra',
                    'label' => 'Extra',
                    'direction' => ChannelDirection::Subscribe->value,
                    'purpose' => ChannelPurpose::Command->value,
                    'transport' => ChannelTransport::Mqtt->value,
                    'address' => 'extra',
                    'parameters' => [],
                ],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $profile = DeviceProfile::query()->where('key', 'http_energy_meter')->firstOrFail();
    $version = $profile->versions()->firstOrFail();
    $channels = $version->channels()->get();
    $channel = $channels->firstOrFail();
    $parameter = $channel->parameters()->firstOrFail();

    expect($version->status)->toBe(DeviceProfileVersion::STATUS_DRAFT)
        ->and($version->protocol)->toBe(Protocol::Http)
        ->and($version->protocol_config?->baseUrl)->toBe('https://devices.example.test')
        ->and($version->protocol_config?->telemetryEndpoint)->toBe('/measurements')
        ->and($version->protocol_config?->method)->toBe('PATCH')
        ->and($channels)->toHaveCount(1)
        ->and($channel->key)->toBe('telemetry')
        ->and($channel->label)->toBe('Telemetry')
        ->and($channel->direction)->toBe(ChannelDirection::Publish)
        ->and($channel->purpose)->toBe(ChannelPurpose::Telemetry)
        ->and($channel->transport)->toBe(ChannelTransport::Http)
        ->and($channel->address)->toBe('/measurements')
        ->and($channel->http_method)->toBe('PATCH')
        ->and($channel->qos)->toBe(0)
        ->and($channel->retain)->toBeFalse()
        ->and($parameter->mutation_expression)->toBeNull();
});

it('rejects invalid mutation expression json during onboarding', function (): void {
    livewire(CreateDeviceProfile::class)
        ->fillForm([
            'name' => 'Invalid Mutation Meter',
            'key' => 'invalid_mutation_meter',
            'organization_id' => null,
            'protocol' => Protocol::Mqtt->value,
            'starter_channels' => [
                [
                    'key' => 'telemetry',
                    'label' => 'Telemetry',
                    'direction' => ChannelDirection::Publish->value,
                    'purpose' => ChannelPurpose::Telemetry->value,
                    'address' => 'telemetry',
                    'parameters' => [
                        [
                            'key' => 'voltage',
                            'label' => 'Voltage',
                            'json_path' => 'voltage',
                            'type' => ParameterDataType::Decimal->value,
                            'category' => ParameterCategory::Measurement->value,
                            'mutation_expression' => '{"*":[{"var":"val"},100]',
                        ],
                    ],
                ],
            ],
        ])
        ->call('create')
        ->assertHasFormErrors([
            'starter_channels.0.parameters.0.mutation_expression' => 'json',
        ]);
});

it('creates channel parameter mutation expression from version channel relation manager', function (): void {
    $version = DeviceProfileVersion::factory()->mqtt()->create();

    livewire(ChannelsRelationManager::class, [
        'ownerRecord' => $version,
        'pageClass' => EditDeviceProfileVersion::class,
    ])
        ->callTableAction('create', data: [
            'key' => 'telemetry',
            'label' => 'Telemetry',
            'direction' => ChannelDirection::Publish->value,
            'purpose' => ChannelPurpose::Telemetry->value,
            'transport' => ChannelTransport::Mqtt->value,
            'address' => 'telemetry',
            'http_method' => '',
            'qos' => 1,
            'retain' => false,
            'parameters' => [
                'temperature_parameter' => [
                    'key' => 'temperature',
                    'label' => 'Temperature',
                    'json_path' => 'temperature',
                    'type' => ParameterDataType::Decimal->value,
                    'category' => ParameterCategory::Measurement->value,
                    'mutation_expression' => '{"var":"val"}',
                ],
            ],
        ])
        ->assertHasNoTableActionErrors();

    $channel = $version->channels()->firstOrFail();
    $parameter = $channel->parameters()->firstOrFail();

    expect($parameter->mutation_expression)->toBe(['var' => 'val']);
});

it('renders version editor allows notes on an active contract', function (): void {
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
