<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Models\DeviceType;
use App\Domain\DeviceSchema\Models\DeviceSchema;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Domain\DeviceSchema\Models\ParameterDefinition;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Domain\Shared\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds a synthetic fleet and schema artifacts for staging load tests', function (): void {
    $this->artisan('iot:seed-simulation-fleet', [
        '--organization' => 'Stage Load Fleet',
        '--devices' => 5,
        '--prefix' => 'stage-sim',
        '--chunk' => 2,
    ])->assertSuccessful();

    $organization = Organization::query()->where('slug', 'stage-load-fleet')->first();

    expect($organization)->not->toBeNull();

    $deviceType = DeviceType::query()
        ->where('organization_id', $organization?->id)
        ->where('key', 'simulation_fleet')
        ->first();

    $schema = DeviceSchema::query()
        ->where('device_type_id', $deviceType?->id)
        ->where('name', 'Simulation Fleet Schema')
        ->first();

    $schemaVersion = DeviceSchemaVersion::query()
        ->where('device_schema_id', $schema?->id)
        ->where('version', 1)
        ->first();

    $topic = SchemaVersionTopic::query()
        ->where('device_schema_version_id', $schemaVersion?->id)
        ->where('key', 'telemetry')
        ->first();

    expect($deviceType)->not->toBeNull()
        ->and($schema)->not->toBeNull()
        ->and($schemaVersion)->not->toBeNull()
        ->and($topic)->not->toBeNull()
        ->and(Device::query()->where('organization_id', $organization?->id)->count())->toBe(5)
        ->and(ParameterDefinition::query()->where('schema_version_topic_id', $topic?->id)->count())->toBe(3);
});
