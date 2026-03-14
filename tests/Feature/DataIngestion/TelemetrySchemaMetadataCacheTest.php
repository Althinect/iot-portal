<?php

declare(strict_types=1);

use App\Domain\DataIngestion\Services\TelemetrySchemaMetadataCache;
use App\Domain\DeviceSchema\Enums\ParameterDataType;
use App\Domain\DeviceSchema\Models\DerivedParameterDefinition;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Domain\DeviceSchema\Models\ParameterDefinition;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('reuses cached active parameter metadata for a topic within the ttl window', function (): void {
    config()->set('ingestion.metadata_ttl_seconds', 300);

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

    expect($first)->toHaveCount(1)
        ->and($first->pluck('key')->all())->toBe(['temp_c'])
        ->and($second->pluck('key')->all())->toBe(['temp_c'])
        ->and(count(DB::getQueryLog()))->toBe(0);
});

it('reuses cached derived parameter metadata for a schema version within the ttl window', function (): void {
    config()->set('ingestion.metadata_ttl_seconds', 300);

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

    expect($first)->toHaveCount(1)
        ->and($first->pluck('key')->all())->toBe(['temp_f'])
        ->and($second->pluck('key')->all())->toBe(['temp_f'])
        ->and(count(DB::getQueryLog()))->toBe(0);
});
