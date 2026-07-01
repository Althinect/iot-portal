<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\ValueObjects\Protocol\MqttProtocolConfig;
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
use App\Domain\DeviceProfile\Services\DeviceProfileVersionDeduplicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('reports duplicate device profile versions with matching effective configuration', function (): void {
    [$canonicalVersion, $duplicateVersion] = duplicateProfileVersionFixture();

    $mappings = app(DeviceProfileVersionDeduplicator::class)->duplicateMappings();

    expect($mappings)->toHaveCount(1)
        ->and($mappings[0]['canonical_id'])->toBe($canonicalVersion->id)
        ->and($mappings[0]['duplicate_id'])->toBe($duplicateVersion->id);

    $this->artisan('device-profiles:deduplicate-versions')
        ->expectsOutputToContain('Dry run only.')
        ->assertSuccessful();
});

it('remaps references and deletes duplicate device profile versions', function (): void {
    [$canonicalVersion, $duplicateVersion, $canonicalChannel, $duplicateChannel] = duplicateProfileVersionFixture();

    $device = Device::factory()->create([
        'device_profile_version_id' => $duplicateVersion->id,
    ]);

    DB::table('device_signal_bindings')->insert([
        'device_id' => $device->id,
        'device_channel_id' => $canonicalChannel->id,
        'parameter_key' => 'temperature',
        'source_topic' => 'factory/acme/telemetry',
        'source_json_path' => 'temperature',
        'source_adapter' => null,
        'sequence' => 0,
        'is_active' => true,
        'metadata' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('device_signal_bindings')->insert([
        'device_id' => $device->id,
        'device_channel_id' => $duplicateChannel->id,
        'parameter_key' => 'temperature',
        'source_topic' => 'factory/acme/telemetry',
        'source_json_path' => 'temperature',
        'source_adapter' => null,
        'sequence' => 0,
        'is_active' => true,
        'metadata' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('device-profiles:deduplicate-versions', ['--force' => true])
        ->expectsOutputToContain('Deleted 1 duplicate device profile versions')
        ->assertSuccessful();

    expect($device->fresh()->device_profile_version_id)->toBe($canonicalVersion->id)
        ->and(DeviceProfileVersion::query()->whereKey($duplicateVersion->id)->exists())->toBeFalse()
        ->and(DB::table('device_signal_bindings')->where('device_channel_id', $duplicateChannel->id)->exists())->toBeFalse()
        ->and(DB::table('device_signal_bindings')->where('device_channel_id', $canonicalChannel->id)->count())->toBe(1);
});

/**
 * @return array{DeviceProfileVersion, DeviceProfileVersion, DeviceChannel, DeviceChannel}
 */
function duplicateProfileVersionFixture(): array
{
    $profile = DeviceProfile::factory()->global()->create([
        'key' => 'acme-meter',
        'name' => 'Acme Meter',
    ]);
    $protocolConfig = (new MqttProtocolConfig(
        brokerHost: 'nats',
        brokerPort: 1883,
        baseTopic: 'factory/acme',
    ))->toArray();

    $canonicalVersion = DeviceProfileVersion::factory()->forProfile($profile)->active()->create([
        'version' => 1,
        'protocol' => Protocol::Mqtt,
        'protocol_config' => $protocolConfig,
        'notes' => 'Initial migration version.',
    ]);
    $duplicateVersion = DeviceProfileVersion::factory()->forProfile($profile)->create([
        'version' => 2,
        'status' => 'retired',
        'protocol' => Protocol::Mqtt,
        'protocol_config' => $protocolConfig,
        'notes' => 'Legacy duplicate schema number.',
    ]);

    $canonicalChannel = matchingTelemetryChannel($canonicalVersion);
    $duplicateChannel = matchingTelemetryChannel($duplicateVersion);

    matchingTemperatureParameter($canonicalChannel);
    matchingTemperatureParameter($duplicateChannel);

    return [$canonicalVersion, $duplicateVersion, $canonicalChannel, $duplicateChannel];
}

function matchingTelemetryChannel(DeviceProfileVersion $version): DeviceChannel
{
    return DeviceChannel::factory()->create([
        'device_profile_version_id' => $version->id,
        'key' => 'telemetry',
        'label' => 'Telemetry',
        'direction' => ChannelDirection::Publish,
        'purpose' => ChannelPurpose::Telemetry,
        'transport' => ChannelTransport::Mqtt,
        'address' => 'telemetry',
        'http_method' => '',
        'description' => null,
        'qos' => 1,
        'retain' => false,
        'sequence' => 0,
        'options' => null,
    ]);
}

function matchingTemperatureParameter(DeviceChannel $channel): ProfileParameterDefinition
{
    return ProfileParameterDefinition::factory()->create([
        'device_channel_id' => $channel->id,
        'key' => 'temperature',
        'label' => 'Temperature',
        'json_path' => 'temperature',
        'type' => ParameterDataType::Decimal,
        'category' => ParameterCategory::Measurement,
        'unit' => 'Celsius',
        'required' => true,
        'is_critical' => true,
        'validation_rules' => ['min' => -40, 'max' => 85],
        'control_ui' => null,
        'validation_error_code' => 'TEMP_OUT_OF_RANGE',
        'mutation_expression' => null,
        'sequence' => 1,
        'is_active' => true,
        'default_value' => null,
    ]);
}
