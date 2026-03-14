<?php

declare(strict_types=1);

use App\Domain\DataIngestion\Services\DeviceTelemetryTopicResolver;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Models\DeviceType;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('reuses the topic registry across resolver calls within the ttl window', function (): void {
    config()->set('ingestion.registry_ttl_seconds', 30);

    $schemaVersion = DeviceSchemaVersion::factory()->create();
    $deviceType = DeviceType::factory()->mqtt()->create([
        'protocol_config' => [
            'broker_host' => 'localhost',
            'broker_port' => 1883,
            'username' => null,
            'password' => null,
            'use_tls' => false,
            'base_topic' => 'devices',
        ],
    ]);

    $topic = SchemaVersionTopic::factory()->publish()->create([
        'device_schema_version_id' => $schemaVersion->id,
        'key' => 'telemetry',
        'suffix' => 'telemetry',
    ]);

    $device = Device::factory()->create([
        'device_type_id' => $deviceType->id,
        'device_schema_version_id' => $schemaVersion->id,
        'external_id' => 'sensor-resolver',
    ]);

    $mqttTopic = $topic->resolvedTopic($device);

    $resolver = app(DeviceTelemetryTopicResolver::class);
    $first = $resolver->resolve($mqttTopic);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $second = app(DeviceTelemetryTopicResolver::class)->resolve($mqttTopic);

    expect($first)->not->toBeNull()
        ->and($first['device']->is($device))->toBeTrue()
        ->and($first['topic']->is($topic))->toBeTrue()
        ->and($second)->not->toBeNull()
        ->and($second['device']->is($device))->toBeTrue()
        ->and($second['topic']->is($topic))->toBeTrue()
        ->and(count(DB::getQueryLog()))->toBe(0);
});
