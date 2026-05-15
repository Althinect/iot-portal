<?php

declare(strict_types=1);

use App\Domain\DataIngestion\Services\TelemetrySchemaMetadataCache;
use App\Domain\DeviceSchema\Enums\ParameterDataType;
use App\Domain\DeviceSchema\Models\DerivedParameterDefinition;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Domain\DeviceSchema\Models\ParameterDefinition;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('cache.default', 'array');
    config()->set('cache.stores.file.path', storage_path('framework/cache/testing/'.Str::uuid()->toString()));
    config()->set('ingestion.metadata_ttl_seconds', 300);

    Cache::purge('file');
    TelemetrySchemaMetadataCache::invalidateSharedVersion();
});

it('reuses cached active parameter metadata for a topic within the ttl window', function (): void {
    $schemaVersion = DeviceSchemaVersion::factory()->create();
    $topic = SchemaVersionTopic::factory()->publish()->create([
        'device_schema_version_id' => $schemaVersion->id,
    ]);

    ParameterDefinition::factory()->create([
        'schema_version_topic_id' => $topic->id,
        'key' => 'temp_c',
        'type' => ParameterDataType::Decimal,
        'is_active' => true,
        'sequence' => 1,
    ]);

    ParameterDefinition::factory()->create([
        'schema_version_topic_id' => $topic->id,
        'key' => 'temp_f',
        'type' => ParameterDataType::Decimal,
        'is_active' => false,
        'sequence' => 2,
    ]);

    $cache = app(TelemetrySchemaMetadataCache::class);

    $first = $cache->activeParametersFor($topic);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $second = $cache->activeParametersFor($topic);
    $parameterDefinitionQueryCount = collect(DB::getQueryLog())
        ->filter(static fn (array $query): bool => str_contains((string) $query['query'], 'parameter_definitions'))
        ->count();

    expect($first)->toHaveCount(1)
        ->and($first->pluck('key')->all())->toBe(['temp_c'])
        ->and($second->pluck('key')->all())->toBe(['temp_c'])
        ->and($parameterDefinitionQueryCount)->toBe(0);
});

it('reuses cached derived parameter metadata for a schema version within the ttl window', function (): void {
    $schemaVersion = DeviceSchemaVersion::factory()->create();

    DerivedParameterDefinition::factory()->create([
        'device_schema_version_id' => $schemaVersion->id,
        'key' => 'temp_f',
    ]);

    $cache = app(TelemetrySchemaMetadataCache::class);

    $first = $cache->derivedParametersFor($schemaVersion);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $second = $cache->derivedParametersFor($schemaVersion);
    $derivedParameterDefinitionQueryCount = collect(DB::getQueryLog())
        ->filter(static fn (array $query): bool => str_contains((string) $query['query'], 'derived_parameter_definitions'))
        ->count();

    expect($first)->toHaveCount(1)
        ->and($first->pluck('key')->all())->toBe(['temp_f'])
        ->and($second->pluck('key')->all())->toBe(['temp_f'])
        ->and($derivedParameterDefinitionQueryCount)->toBe(0);
});

it('refreshes cached active parameter metadata immediately after parameter changes', function (): void {
    $schemaVersion = DeviceSchemaVersion::factory()->create();
    $topic = SchemaVersionTopic::factory()->publish()->create([
        'device_schema_version_id' => $schemaVersion->id,
    ]);

    ParameterDefinition::factory()->create([
        'schema_version_topic_id' => $topic->id,
        'key' => 'temp_c',
        'type' => ParameterDataType::Decimal,
        'is_active' => true,
        'sequence' => 1,
    ]);

    $cache = app(TelemetrySchemaMetadataCache::class);

    $first = $cache->activeParametersFor($topic);

    ParameterDefinition::factory()->create([
        'schema_version_topic_id' => $topic->id,
        'key' => 'humidity',
        'type' => ParameterDataType::Decimal,
        'is_active' => true,
        'sequence' => 2,
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $second = $cache->activeParametersFor($topic);

    expect($first->pluck('key')->all())->toBe(['temp_c'])
        ->and($second->pluck('key')->all())->toBe(['temp_c', 'humidity'])
        ->and(count(DB::getQueryLog()))->toBeGreaterThan(0);
});

it('refreshes cached derived parameter metadata immediately after schema changes', function (): void {
    $schemaVersion = DeviceSchemaVersion::factory()->create();

    DerivedParameterDefinition::factory()->create([
        'device_schema_version_id' => $schemaVersion->id,
        'key' => 'temp_f',
    ]);

    $cache = app(TelemetrySchemaMetadataCache::class);

    $first = $cache->derivedParametersFor($schemaVersion);

    DerivedParameterDefinition::factory()->create([
        'device_schema_version_id' => $schemaVersion->id,
        'key' => 'temp_k',
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $second = $cache->derivedParametersFor($schemaVersion);

    expect($first->pluck('key')->all())->toBe(['temp_f'])
        ->and($second->pluck('key')->all())->toBe(['temp_f', 'temp_k'])
        ->and(count(DB::getQueryLog()))->toBeGreaterThan(0);
});
