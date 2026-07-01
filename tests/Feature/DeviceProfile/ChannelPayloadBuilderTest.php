<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\ValueObjects\Protocol\MqttProtocolConfig;
use App\Domain\DeviceProfile\Enums\ControlWidgetType;
use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use App\Domain\DeviceProfile\Services\DeviceProfileContractResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function resolveChannelDto(DeviceChannel $channel)
{
    $version = $channel->version;

    return app(DeviceProfileContractResolver::class)
        ->resolve($version)
        ->channelByKey($channel->key);
}

function createParameter(DeviceChannel $channel, array $attributes): ProfileParameterDefinition
{
    return ProfileParameterDefinition::factory()->create(array_merge([
        'device_channel_id' => $channel->id,
        'is_active' => true,
    ], $attributes));
}

test('buildCommandPayloadTemplate creates flat payload from subscribe parameters', function (): void {
    $channel = DeviceChannel::factory()->subscribe()->create();

    createParameter($channel, [
        'key' => 'fan_speed',
        'json_path' => 'fan_speed',
        'type' => ParameterDataType::Integer,
        'default_value' => 50,
        'sequence' => 1,
    ]);
    createParameter($channel, [
        'key' => 'light_state',
        'json_path' => 'light_state',
        'type' => ParameterDataType::Boolean,
        'default_value' => true,
        'sequence' => 2,
    ]);
    createParameter($channel, [
        'key' => 'mode',
        'json_path' => 'mode',
        'type' => ParameterDataType::String,
        'default_value' => 'cooling',
        'sequence' => 3,
    ]);

    $channelDto = resolveChannelDto($channel);

    expect($channelDto->buildCommandPayloadTemplate())->toBe([
        'fan_speed' => 50,
        'light_state' => true,
        'mode' => 'cooling',
    ]);
});

test('buildCommandPayloadTemplate creates nested payload using json_path', function (): void {
    $channel = DeviceChannel::factory()->subscribe()->create();

    createParameter($channel, [
        'key' => 'fan_speed',
        'json_path' => 'control.fan_speed',
        'type' => ParameterDataType::Integer,
        'default_value' => 75,
        'sequence' => 1,
    ]);
    createParameter($channel, [
        'key' => 'light',
        'json_path' => 'control.light',
        'type' => ParameterDataType::Boolean,
        'default_value' => false,
        'sequence' => 2,
    ]);

    $channelDto = resolveChannelDto($channel);

    expect($channelDto->buildCommandPayloadTemplate())->toBe([
        'control' => [
            'fan_speed' => 75,
            'light' => false,
        ],
    ]);
});

test('buildCommandPayloadTemplate uses type defaults when no default value set', function (): void {
    $channel = DeviceChannel::factory()->subscribe()->create();

    createParameter($channel, [
        'key' => 'speed',
        'json_path' => 'speed',
        'type' => ParameterDataType::Integer,
        'default_value' => null,
        'sequence' => 1,
    ]);
    createParameter($channel, [
        'key' => 'enabled',
        'json_path' => 'enabled',
        'type' => ParameterDataType::Boolean,
        'default_value' => null,
        'sequence' => 2,
    ]);

    $channelDto = resolveChannelDto($channel);

    expect($channelDto->buildCommandPayloadTemplate())->toBe([
        'speed' => 0,
        'enabled' => false,
    ]);
});

test('buildCommandPayloadTemplate excludes inactive parameters', function (): void {
    $channel = DeviceChannel::factory()->subscribe()->create();

    createParameter($channel, [
        'key' => 'active_param',
        'json_path' => 'active_param',
        'type' => ParameterDataType::Integer,
        'default_value' => 10,
        'sequence' => 1,
    ]);
    createParameter($channel, [
        'key' => 'inactive_param',
        'json_path' => 'inactive_param',
        'type' => ParameterDataType::Integer,
        'default_value' => 20,
        'is_active' => false,
        'sequence' => 2,
    ]);

    $channelDto = resolveChannelDto($channel);

    expect($channelDto->buildCommandPayloadTemplate())->toBe(['active_param' => 10])
        ->and($channelDto->buildCommandPayloadTemplate())->not->toHaveKey('inactive_param');
});

test('buildCommandPayloadTemplate omits optional button parameters by default', function (): void {
    $channel = DeviceChannel::factory()->subscribe()->create();

    createParameter($channel, [
        'key' => 'brightness',
        'json_path' => 'brightness',
        'type' => ParameterDataType::Integer,
        'default_value' => 42,
        'sequence' => 1,
    ]);
    createParameter($channel, [
        'key' => 'send_now',
        'json_path' => 'send_now',
        'type' => ParameterDataType::Boolean,
        'default_value' => false,
        'required' => false,
        'control_ui' => [
            'widget' => ControlWidgetType::Button->value,
            'button_value' => true,
        ],
        'sequence' => 2,
    ]);

    $channelDto = resolveChannelDto($channel);

    expect($channelDto->buildCommandPayloadTemplate())->toBe(['brightness' => 42])
        ->and($channelDto->buildCommandPayloadTemplate())->not->toHaveKey('send_now');
});

test('buildPublishPayloadTemplate creates nested payload using JSONPath like json_path', function (): void {
    $channel = DeviceChannel::factory()->publish()->create();

    createParameter($channel, [
        'key' => 'temp_c',
        'json_path' => '$.status.temp',
        'type' => ParameterDataType::Decimal,
        'default_value' => null,
        'sequence' => 1,
    ]);
    createParameter($channel, [
        'key' => 'humidity',
        'json_path' => '$.status.humidity',
        'type' => ParameterDataType::Decimal,
        'default_value' => null,
        'sequence' => 2,
    ]);

    $channelDto = resolveChannelDto($channel);

    expect($channelDto->buildPublishPayloadTemplate())->toBe([
        'status' => [
            'temp' => 0.0,
            'humidity' => 0.0,
        ],
    ]);
});

test('resolvedAddress builds mqtt topic from base topic identifier and address', function (): void {
    $version = DeviceProfileVersion::factory()->mqtt()->create([
        'protocol_config' => (new MqttProtocolConfig(
            brokerHost: 'broker.test',
            baseTopic: 'sensors',
        ))->toArray(),
    ]);

    $channel = DeviceChannel::factory()->telemetry()->create([
        'device_profile_version_id' => $version->id,
        'address' => 'telemetry',
    ]);

    $channelDto = resolveChannelDto($channel);
    $contract = app(DeviceProfileContractResolver::class)->resolve($version);

    expect($channelDto->resolvedAddress('sensor-001', $contract->protocolConfig))
        ->toBe('sensors/sensor-001/telemetry');
});
