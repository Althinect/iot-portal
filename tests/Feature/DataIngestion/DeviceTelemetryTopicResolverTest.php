<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\ValueObjects\Protocol\MqttProtocolConfig;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Services\ProfileChannelResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('reuses the profile channel registry across resolver calls within the ttl window', function (): void {
    config()->set('device-profile.channel_registry_ttl_seconds', 30);

    $profileVersion = DeviceProfileVersion::factory()->active()->mqtt()->create([
        'protocol_config' => (new MqttProtocolConfig(
            brokerHost: 'localhost',
            brokerPort: 1883,
            baseTopic: 'devices',
        ))->toArray(),
    ]);

    $channel = DeviceChannel::factory()->telemetry()->create([
        'device_profile_version_id' => $profileVersion->id,
        'key' => 'telemetry',
        'address' => 'telemetry',
    ]);

    $device = Device::factory()->create([
        'device_profile_version_id' => $profileVersion->id,
        'external_id' => 'sensor-resolver',
    ]);

    $mqttTopic = 'devices/sensor-resolver/telemetry';
    $resolver = app(ProfileChannelResolver::class);
    $first = $resolver->resolve($mqttTopic);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $second = $resolver->resolve($mqttTopic);

    expect($first)->not->toBeNull()
        ->and($first['device']->is($device))->toBeTrue()
        ->and($first['channel']->id)->toBe($channel->id)
        ->and($second)->not->toBeNull()
        ->and($second['device']->is($device))->toBeTrue()
        ->and($second['channel']->id)->toBe($channel->id)
        ->and(count(DB::getQueryLog()))->toBe(0);
});
