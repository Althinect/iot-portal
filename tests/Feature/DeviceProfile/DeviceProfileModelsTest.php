<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\ValueObjects\Protocol\HttpProtocolConfig;
use App\Domain\DeviceManagement\ValueObjects\Protocol\MqttProtocolConfig;
use App\Domain\DeviceProfile\Enums\ChannelDirection;
use App\Domain\DeviceProfile\Enums\ChannelTransport;
use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Enums\Protocol;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\DeviceProfile;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Models\DeviceTwin;
use App\Domain\DeviceProfile\Models\ProfileDerivedParameterDefinition;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('device profile can be created with factory', function (): void {
    $profile = DeviceProfile::factory()->create();

    expect($profile)
        ->toBeInstanceOf(DeviceProfile::class)
        ->id->toBeInt()
        ->key->toBeString()
        ->name->toBeString()
        ->isGlobal()->toBeFalse();
});

test('device profile global state sets organization id to null', function (): void {
    $profile = DeviceProfile::factory()->global()->create();

    expect($profile->organization_id)->toBeNull()
        ->and($profile->isGlobal())->toBeTrue();
});

test('device profile has many versions', function (): void {
    $profile = DeviceProfile::factory()->create();
    DeviceProfileVersion::factory()->forProfile($profile)->create(['version' => 1]);
    DeviceProfileVersion::factory()->forProfile($profile)->create(['version' => 2]);

    expect($profile->versions)->toHaveCount(2);
});

test('device profile version can be created with factory', function (): void {
    $version = DeviceProfileVersion::factory()->mqtt()->active()->create();

    expect($version)
        ->toBeInstanceOf(DeviceProfileVersion::class)
        ->version->toBeInt()
        ->status->toBe('active')
        ->protocol->toBeInstanceOf(Protocol::class)
        ->isActive()->toBeTrue();
});

test('device profile version casts protocol config to typed value object', function (): void {
    $version = DeviceProfileVersion::factory()->mqtt()->create();

    expect($version->getProtocolConfig())
        ->toBeInstanceOf(MqttProtocolConfig::class);

    $httpVersion = DeviceProfileVersion::factory()->http()->create();

    expect($httpVersion->getProtocolConfig())
        ->toBeInstanceOf(HttpProtocolConfig::class);
});

test('device channel can be created with factory', function (): void {
    $channel = DeviceChannel::factory()->create();

    expect($channel)
        ->toBeInstanceOf(DeviceChannel::class)
        ->direction->toBeInstanceOf(ChannelDirection::class)
        ->transport->toBeInstanceOf(ChannelTransport::class);
});

test('device channel belongs to a profile version', function (): void {
    $version = DeviceProfileVersion::factory()->create();
    $channel = DeviceChannel::factory()->create([
        'device_profile_version_id' => $version->id,
    ]);

    expect($channel->version->id)->toBe($version->id);
});

test('device channel has many parameters', function (): void {
    $channel = DeviceChannel::factory()->publish()->create();

    ProfileParameterDefinition::factory()->count(3)->create([
        'device_channel_id' => $channel->id,
    ]);

    expect($channel->parameters)->toHaveCount(3);
});

test('publish factory state sets direction to publish', function (): void {
    $channel = DeviceChannel::factory()->publish()->create();

    expect($channel->direction)->toBe(ChannelDirection::Publish)
        ->and($channel->isPublish())->toBeTrue()
        ->and($channel->isSubscribe())->toBeFalse();
});

test('telemetry state sets telemetry purpose and address', function (): void {
    $channel = DeviceChannel::factory()->telemetry()->create();

    expect($channel->isPurposeTelemetry())->toBeTrue()
        ->and($channel->address)->toBe('telemetry');
});

test('http state sets http transport', function (): void {
    $channel = DeviceChannel::factory()->http()->publish()->create();

    expect($channel->transport)->toBe(ChannelTransport::Http);
});

test('unique key constraint per profile version', function (): void {
    $version = DeviceProfileVersion::factory()->create();

    DeviceChannel::factory()->create([
        'device_profile_version_id' => $version->id,
        'key' => 'telemetry',
        'address' => 'telemetry',
    ]);

    expect(fn () => DeviceChannel::factory()->create([
        'device_profile_version_id' => $version->id,
        'key' => 'telemetry',
        'address' => 'telemetry_dup',
    ]))->toThrow(QueryException::class);
});

test('unique address constraint per profile version', function (): void {
    $version = DeviceProfileVersion::factory()->create();

    DeviceChannel::factory()->create([
        'device_profile_version_id' => $version->id,
        'key' => 'channel_a',
        'address' => 'data',
    ]);

    expect(fn () => DeviceChannel::factory()->create([
        'device_profile_version_id' => $version->id,
        'key' => 'channel_b',
        'address' => 'data',
    ]))->toThrow(QueryException::class);
});

test('deleting a channel cascades to its parameters', function (): void {
    $channel = DeviceChannel::factory()->publish()->create();

    ProfileParameterDefinition::factory()->count(3)->create([
        'device_channel_id' => $channel->id,
    ]);

    expect(ProfileParameterDefinition::where('device_channel_id', $channel->id)->count())->toBe(3);

    $channel->delete();

    expect(ProfileParameterDefinition::where('device_channel_id', $channel->id)->count())->toBe(0);
});

test('profile parameter definition casts type to enum', function (): void {
    $parameter = ProfileParameterDefinition::factory()->create([
        'type' => ParameterDataType::Decimal,
    ]);

    expect($parameter->type)->toBe(ParameterDataType::Decimal);
});

test('derived parameter definition belongs to a profile version', function (): void {
    $version = DeviceProfileVersion::factory()->create();
    $derived = ProfileDerivedParameterDefinition::factory()->create([
        'device_profile_version_id' => $version->id,
    ]);

    expect($derived->version->id)->toBe($version->id);
});

test('a device can be assigned to a profile version', function (): void {
    $version = DeviceProfileVersion::factory()->active()->create();

    $device = Device::factory()->create([
        'device_profile_version_id' => $version->id,
    ]);

    expect($device->profileVersion->id)->toBe($version->id);
});

test('a device has a twin', function (): void {
    $device = Device::factory()->create();

    DeviceTwin::create([
        'device_id' => $device->id,
        'tags' => ['plant' => 'alpha'],
        'desired' => ['reporting_interval' => 30],
        'reported' => ['firmware' => '1.2.0'],
    ]);

    expect($device->twin)->toBeInstanceOf(DeviceTwin::class)
        ->and($device->twin->tags)->toBe(['plant' => 'alpha']);
});
