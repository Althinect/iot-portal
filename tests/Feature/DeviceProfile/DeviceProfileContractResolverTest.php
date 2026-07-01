<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\ValueObjects\Protocol\MqttProtocolConfig;
use App\Domain\DeviceProfile\DTO\ChannelDefinition;
use App\Domain\DeviceProfile\DTO\DeviceProfileContract;
use App\Domain\DeviceProfile\DTO\ParameterDefinitionData;
use App\Domain\DeviceProfile\Enums\ChannelPurpose;
use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Enums\Protocol;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Models\ProfileDerivedParameterDefinition;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use App\Domain\DeviceProfile\Services\DeviceProfileContractResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('contract resolver builds a contract dto from a profile version', function (): void {
    $version = DeviceProfileVersion::factory()->mqtt()->active()->create([
        'protocol_config' => (new MqttProtocolConfig(
            brokerHost: 'broker.test',
            baseTopic: 'sensors',
        ))->toArray(),
    ]);

    $telemetry = DeviceChannel::factory()->telemetry()->create([
        'device_profile_version_id' => $version->id,
    ]);

    ProfileParameterDefinition::factory()->create([
        'device_channel_id' => $telemetry->id,
        'key' => 'temp',
        'json_path' => 'status.temp',
        'type' => ParameterDataType::Decimal,
        'is_active' => true,
        'sequence' => 1,
    ]);

    ProfileDerivedParameterDefinition::factory()->create([
        'device_profile_version_id' => $version->id,
        'key' => 'temp_f',
        'dependencies' => ['temp'],
        'expression' => ['+' => [['var' => 'temp'], 32]],
    ]);

    $resolver = app(DeviceProfileContractResolver::class);
    $contract = $resolver->resolve($version);

    expect($contract)->toBeInstanceOf(DeviceProfileContract::class)
        ->and($contract->versionId)->toBe($version->id)
        ->and($contract->protocol)->toBe(Protocol::Mqtt)
        ->and($contract->protocolConfig)->toBeInstanceOf(MqttProtocolConfig::class)
        ->and($contract->protocolConfig->getBaseTopic())->toBe('sensors')
        ->and($contract->channels)->toHaveCount(1);

    $channel = $contract->channelByKey('telemetry');
    expect($channel)->toBeInstanceOf(ChannelDefinition::class)
        ->and($channel->isPurposeTelemetry())->toBeTrue()
        ->and($channel->parameters)->toHaveCount(1);

    $parameter = $channel->parameters[0];
    expect($parameter)->toBeInstanceOf(ParameterDefinitionData::class)
        ->and($parameter->key)->toBe('temp')
        ->and($parameter->type)->toBe(ParameterDataType::Decimal);

    expect($contract->derivedParameters)->toHaveCount(1)
        ->and($contract->derivedParameters[0]->key)->toBe('temp_f')
        ->and($contract->derivedParameters[0]->resolvedDependencies())->toBe(['temp']);
});

test('contract resolver reuses the same contract when nothing changes', function (): void {
    $version = DeviceProfileVersion::factory()->create();
    DeviceChannel::factory()->telemetry()->create([
        'device_profile_version_id' => $version->id,
    ]);

    $resolver = app(DeviceProfileContractResolver::class);
    $first = $resolver->resolve($version);
    $second = $resolver->resolve($version);

    expect($second)->toBe($first)
        ->and($second->channels)->toHaveCount(1);
});

test('contract resolver invalidates when profile contract models change', function (): void {
    $version = DeviceProfileVersion::factory()->create();
    DeviceChannel::factory()->telemetry()->create([
        'device_profile_version_id' => $version->id,
    ]);

    $resolver = app(DeviceProfileContractResolver::class);
    $first = $resolver->resolve($version);

    DeviceChannel::factory()->state(['purpose' => ChannelPurpose::State->value])->create([
        'device_profile_version_id' => $version->id,
        'key' => 'state',
        'address' => 'state',
    ]);

    $second = $resolver->resolve($version);

    expect($second)->not->toBe($first)
        ->and($second->channels)->toHaveCount(2);
});

test('contract resolver resolve by id survives shared cache invalidation', function (): void {
    $version = DeviceProfileVersion::factory()->create();
    DeviceChannel::factory()->telemetry()->create([
        'device_profile_version_id' => $version->id,
    ]);

    $resolver = app(DeviceProfileContractResolver::class);
    $resolver->resolve($version);

    DeviceProfileContractResolver::invalidateSharedVersion();

    $resolved = $resolver->resolveById((int) $version->id);

    expect($resolved)->toBeInstanceOf(DeviceProfileContract::class)
        ->and($resolved->versionId)->toBe($version->id);
});

test('contract resolver resolve by id returns null for missing version', function (): void {
    $resolver = app(DeviceProfileContractResolver::class);

    expect($resolver->resolveById(999999))->toBeNull();
});
