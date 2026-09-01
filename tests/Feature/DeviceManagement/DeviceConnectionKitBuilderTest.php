<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Enums\MqttSecurityMode;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Services\DeviceConnectionKitBuilder;
use App\Domain\DeviceManagement\ValueObjects\Protocol\MqttProtocolConfig;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\DeviceProfile;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\Shared\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('builds a secret-free MQTT connection kit from the assigned profile', function (): void {
    $profile = DeviceProfile::factory()->global()->create(['name' => 'Boiler Profile']);
    $profileVersion = DeviceProfileVersion::factory()->active()->mqtt()->create([
        'device_profile_id' => $profile->id,
        'protocol_config' => new MqttProtocolConfig(
            brokerHost: 'mqtt.example.test',
            brokerPort: 8883,
            username: 'shared-user',
            password: 'shared-secret',
            useTls: true,
            baseTopic: 'factory/devices',
            securityMode: MqttSecurityMode::X509Mtls,
        ),
    ]);
    DeviceChannel::factory()->telemetry()->create([
        'device_profile_version_id' => $profileVersion->id,
        'key' => 'telemetry',
        'label' => 'Telemetry',
        'address' => 'telemetry',
        'qos' => 1,
    ]);
    DeviceChannel::factory()->command()->create([
        'device_profile_version_id' => $profileVersion->id,
        'key' => 'command',
        'label' => 'Command',
        'address' => 'command',
    ]);
    $device = Device::factory()->create([
        'organization_id' => Organization::factory(),
        'device_profile_version_id' => $profileVersion->id,
        'external_id' => 'boiler-01',
    ]);

    $kit = app(DeviceConnectionKitBuilder::class)->build($device);
    $encodedKit = json_encode($kit, JSON_THROW_ON_ERROR);

    expect($kit['device']['client_id'])->toBe('boiler-01')
        ->and($kit['profile'])->toBe(['name' => 'Boiler Profile', 'version' => 1])
        ->and($kit['mqtt']['broker_host'])->toBe('mqtt.example.test')
        ->and($kit['mqtt']['broker_port'])->toBe(8883)
        ->and($kit['mqtt']['x509_enabled'])->toBeTrue()
        ->and($kit['mqtt']['channels'])->toHaveCount(2)
        ->and(collect($kit['mqtt']['channels'])->firstWhere('key', 'telemetry')['address'])
        ->toBe('factory/devices/boiler-01/telemetry')
        ->and($encodedKit)->not->toContain('shared-user')
        ->and($encodedKit)->not->toContain('shared-secret');
});

it('falls back to the device UUID when no external identifier exists', function (): void {
    $profileVersion = DeviceProfileVersion::factory()->active()->mqtt()->create([
        'protocol_config' => new MqttProtocolConfig('mqtt.example.test', baseTopic: 'devices'),
    ]);
    DeviceChannel::factory()->telemetry()->create([
        'device_profile_version_id' => $profileVersion->id,
        'address' => 'state',
    ]);
    $device = Device::factory()->create([
        'device_profile_version_id' => $profileVersion->id,
        'external_id' => null,
    ]);

    $kit = app(DeviceConnectionKitBuilder::class)->build($device);

    expect($kit['device']['identifier'])->toBe($device->uuid)
        ->and($kit['mqtt']['channels'][0]['address'])->toBe("devices/{$device->uuid}/state");
});
