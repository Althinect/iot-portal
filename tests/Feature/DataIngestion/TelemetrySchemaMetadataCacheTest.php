<?php

declare(strict_types=1);

use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Models\ProfileDerivedParameterDefinition;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use App\Domain\DeviceProfile\Services\DeviceProfileContractResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('cache.default', 'array');
    config()->set('cache.stores.file.path', storage_path('framework/cache/testing/'.Str::uuid()->toString()));
    config()->set('device-profile.contract_ttl_seconds', 300);

    Cache::purge('file');
    DeviceProfileContractResolver::invalidateSharedVersion();
});

it('reuses cached active profile parameter metadata for a channel within the ttl window', function (): void {
    $profileVersion = DeviceProfileVersion::factory()->active()->mqtt()->create();
    $channel = DeviceChannel::factory()->telemetry()->create([
        'device_profile_version_id' => $profileVersion->id,
    ]);

    ProfileParameterDefinition::factory()->create([
        'device_channel_id' => $channel->id,
        'key' => 'temp_c',
        'type' => ParameterDataType::Decimal,
        'is_active' => true,
        'sequence' => 1,
    ]);

    ProfileParameterDefinition::factory()->create([
        'device_channel_id' => $channel->id,
        'key' => 'temp_f',
        'type' => ParameterDataType::Decimal,
        'is_active' => false,
        'sequence' => 2,
    ]);

    $resolver = app(DeviceProfileContractResolver::class);

    $first = $resolver->resolve($profileVersion)->channelByKey('telemetry')?->activeParameters() ?? [];

    DB::flushQueryLog();
    DB::enableQueryLog();

    $second = $resolver->resolve($profileVersion)->channelByKey('telemetry')?->activeParameters() ?? [];
    $parameterQueryCount = collect(DB::getQueryLog())
        ->filter(static fn (array $query): bool => str_contains((string) $query['query'], 'profile_parameter_definitions'))
        ->count();

    expect($first)->toHaveCount(1)
        ->and(collect($first)->pluck('key')->all())->toBe(['temp_c'])
        ->and(collect($second)->pluck('key')->all())->toBe(['temp_c'])
        ->and($parameterQueryCount)->toBe(0);
});

it('reuses cached profile derived parameter metadata for a profile version within the ttl window', function (): void {
    $profileVersion = DeviceProfileVersion::factory()->active()->mqtt()->create();

    ProfileDerivedParameterDefinition::factory()->create([
        'device_profile_version_id' => $profileVersion->id,
        'key' => 'temp_f',
    ]);

    $resolver = app(DeviceProfileContractResolver::class);

    $first = $resolver->resolve($profileVersion)->derivedParameters();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $second = $resolver->resolve($profileVersion)->derivedParameters();
    $derivedQueryCount = collect(DB::getQueryLog())
        ->filter(static fn (array $query): bool => str_contains((string) $query['query'], 'profile_derived_parameter_definitions'))
        ->count();

    expect($first)->toHaveCount(1)
        ->and($first->pluck('key')->all())->toBe(['temp_f'])
        ->and($second->pluck('key')->all())->toBe(['temp_f'])
        ->and($derivedQueryCount)->toBe(0);
});

it('refreshes cached active profile parameter metadata immediately after parameter changes', function (): void {
    $profileVersion = DeviceProfileVersion::factory()->active()->mqtt()->create();
    $channel = DeviceChannel::factory()->telemetry()->create([
        'device_profile_version_id' => $profileVersion->id,
    ]);

    ProfileParameterDefinition::factory()->create([
        'device_channel_id' => $channel->id,
        'key' => 'temp_c',
        'type' => ParameterDataType::Decimal,
        'is_active' => true,
        'sequence' => 1,
    ]);

    $resolver = app(DeviceProfileContractResolver::class);

    $first = $resolver->resolve($profileVersion)->channelByKey('telemetry')?->activeParameters() ?? [];

    ProfileParameterDefinition::factory()->create([
        'device_channel_id' => $channel->id,
        'key' => 'humidity',
        'type' => ParameterDataType::Decimal,
        'is_active' => true,
        'sequence' => 2,
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $second = $resolver->resolve($profileVersion)->channelByKey('telemetry')?->activeParameters() ?? [];

    expect(collect($first)->pluck('key')->all())->toBe(['temp_c'])
        ->and(collect($second)->pluck('key')->all())->toBe(['temp_c', 'humidity'])
        ->and(count(DB::getQueryLog()))->toBeGreaterThan(0);
});

it('refreshes cached profile derived parameter metadata immediately after profile changes', function (): void {
    $profileVersion = DeviceProfileVersion::factory()->active()->mqtt()->create();

    ProfileDerivedParameterDefinition::factory()->create([
        'device_profile_version_id' => $profileVersion->id,
        'key' => 'temp_f',
    ]);

    $resolver = app(DeviceProfileContractResolver::class);

    $first = $resolver->resolve($profileVersion)->derivedParameters();

    ProfileDerivedParameterDefinition::factory()->create([
        'device_profile_version_id' => $profileVersion->id,
        'key' => 'temp_k',
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $second = $resolver->resolve($profileVersion)->derivedParameters();

    expect($first->pluck('key')->all())->toBe(['temp_f'])
        ->and($second->pluck('key')->sort()->values()->all())->toBe(['temp_f', 'temp_k'])
        ->and(count(DB::getQueryLog()))->toBeGreaterThan(0);
});
