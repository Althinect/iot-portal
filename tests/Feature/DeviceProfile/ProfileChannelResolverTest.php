<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\ValueObjects\Protocol\HttpProtocolConfig;
use App\Domain\DeviceManagement\ValueObjects\Protocol\MqttProtocolConfig;
use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use App\Domain\DeviceProfile\Services\ProfileChannelResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createProfileDevice(string $externalId, DeviceProfileVersion $version): Device
{
    return Device::factory()->create([
        'device_profile_version_id' => $version->id,
        'external_id' => $externalId,
        'is_active' => true,
    ]);
}

test('channel resolver resolves an mqtt telemetry topic to device and channel', function (): void {
    $version = DeviceProfileVersion::factory()->mqtt()->active()->create([
        'protocol_config' => (new MqttProtocolConfig(
            brokerHost: 'broker.test',
            baseTopic: 'sensors',
        ))->toArray(),
    ]);

    $channel = DeviceChannel::factory()->telemetry()->create([
        'device_profile_version_id' => $version->id,
        'address' => 'telemetry',
    ]);

    ProfileParameterDefinition::factory()->create([
        'device_channel_id' => $channel->id,
        'key' => 'temp',
        'json_path' => 'temp',
        'type' => ParameterDataType::Decimal,
        'is_active' => true,
    ]);

    $device = createProfileDevice('sensor-001', $version);

    $resolver = app(ProfileChannelResolver::class);
    $resolved = $resolver->resolve('sensors/sensor-001/telemetry');

    expect($resolved)->not->toBeNull()
        ->and($resolved['device']->id)->toBe($device->id)
        ->and($resolved['channel']->id)->toBe($channel->id)
        ->and($resolved['channel']->key)->toBe('telemetry')
        ->and($resolved['contract']->versionId)->toBe($version->id);
});

test('channel resolver resolves an http telemetry path to device and channel', function (): void {
    $version = DeviceProfileVersion::factory()->http()->active()->create([
        'protocol_config' => (new HttpProtocolConfig(
            baseUrl: 'https://api.example.test',
        ))->toArray(),
    ]);

    $channel = DeviceChannel::factory()->http()->telemetry()->create([
        'device_profile_version_id' => $version->id,
        'address' => '/telemetry',
    ]);

    ParameterParameterForChannel($channel);

    $device = createProfileDevice('http-sensor-9', $version);

    $resolver = app(ProfileChannelResolver::class);
    $resolved = $resolver->resolve('https://api.example.test/devices/http-sensor-9/telemetry');

    expect($resolved)->not->toBeNull()
        ->and($resolved['device']->id)->toBe($device->id)
        ->and($resolved['channel']->id)->toBe($channel->id);
});

test('channel resolver differentiates http channels by method for the same path', function (): void {
    $version = DeviceProfileVersion::factory()->http()->active()->create([
        'protocol_config' => (new HttpProtocolConfig(
            baseUrl: 'https://api.example.test',
            method: 'POST',
        ))->toArray(),
    ]);

    $postChannel = DeviceChannel::factory()->http()->telemetry()->create([
        'device_profile_version_id' => $version->id,
        'key' => 'state_update',
        'address' => '/state',
        'http_method' => 'POST',
    ]);

    $getChannel = DeviceChannel::factory()->http()->telemetry()->create([
        'device_profile_version_id' => $version->id,
        'key' => 'state_read',
        'address' => '/state',
        'http_method' => 'GET',
    ]);

    ParameterParameterForChannel($postChannel);
    ParameterParameterForChannel($getChannel);

    createProfileDevice('http-sensor-10', $version);

    $resolver = app(ProfileChannelResolver::class);
    $address = 'https://api.example.test/devices/http-sensor-10/state';

    expect($resolver->resolve($address, 'POST')['channel']->id)->toBe($postChannel->id)
        ->and($resolver->resolve($address, 'GET')['channel']->id)->toBe($getChannel->id);
});

test('channel resolver resolves an http channel using a device placeholder address', function (): void {
    $version = DeviceProfileVersion::factory()->http()->active()->create([
        'protocol_config' => (new HttpProtocolConfig(
            baseUrl: 'https://api.example.test',
        ))->toArray(),
    ]);

    $channel = DeviceChannel::factory()->http()->telemetry()->create([
        'device_profile_version_id' => $version->id,
        'address' => '/devices/{device}/state',
    ]);

    ParameterParameterForChannel($channel);

    $device = createProfileDevice('dev-42', $version);

    $resolver = app(ProfileChannelResolver::class);
    $resolved = $resolver->resolve('https://api.example.test/devices/dev-42/state');

    expect($resolved)->not->toBeNull()
        ->and($resolved['channel']->id)->toBe($channel->id);
});

test('channel resolver returns null for unknown address', function (): void {
    $resolver = app(ProfileChannelResolver::class);

    expect($resolver->resolve('unknown/path'))->toBeNull();
});

test('channel resolver returns null when no profiled device owns the address', function (): void {
    $resolver = app(ProfileChannelResolver::class);

    expect($resolver->resolve('any/path'))->toBeNull();
});

function ParameterParameterForChannel(DeviceChannel $channel): void
{
    ProfileParameterDefinition::factory()->create([
        'device_channel_id' => $channel->id,
        'key' => 'value',
        'json_path' => 'value',
        'type' => ParameterDataType::Decimal,
        'is_active' => true,
    ]);
}
