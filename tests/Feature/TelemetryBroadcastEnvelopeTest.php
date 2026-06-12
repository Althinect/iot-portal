<?php

declare(strict_types=1);

use App\Console\Commands\IoT\IngestTelemetryCommand;
use App\Domain\DataIngestion\DTO\IncomingTelemetryEnvelope;
use App\Domain\DataIngestion\Models\DeviceSignalBinding;
use App\Domain\DataIngestion\Services\DeviceSignalBindingResolver;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceSchema\Enums\ParameterDataType;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Domain\DeviceSchema\Models\ParameterDefinition;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;

uses(RefreshDatabase::class);

it('enriches direct telemetry broadcast envelopes with resolved device identifiers', function (): void {
    $schemaVersion = DeviceSchemaVersion::factory()->create();
    $topic = SchemaVersionTopic::factory()->publish()->create([
        'device_schema_version_id' => $schemaVersion->id,
        'suffix' => 'telemetry',
        'label' => 'Telemetry',
    ]);
    $device = Device::factory()->create([
        'device_schema_version_id' => $schemaVersion->id,
        'external_id' => '869244041759402-22',
    ]);
    $mqttTopic = $topic->resolvedTopic($device);

    $envelopes = telemetryBroadcastEnvelopes(
        new IncomingTelemetryEnvelope(
            sourceSubject: str_replace('/', '.', $mqttTopic),
            mqttTopic: $mqttTopic,
            payload: ['TotalEnergy' => 20488680],
            receivedAt: now(),
        ),
        ['device' => $device, 'topic' => $topic],
        false,
    );

    expect($envelopes)->toHaveCount(1)
        ->and($envelopes->first())->toBeInstanceOf(IncomingTelemetryEnvelope::class)
        ->and($envelopes->first()?->mqttTopic)->toBe($mqttTopic)
        ->and($envelopes->first()?->deviceUuid)->toBe($device->uuid)
        ->and($envelopes->first()?->deviceExternalId)->toBe('869244041759402-22')
        ->and($envelopes->first()?->payload)->toMatchArray(['TotalEnergy' => 20488680]);
});

it('expands signal-bound source telemetry before broadcasting diagnostics', function (): void {
    $schemaVersion = DeviceSchemaVersion::factory()->create();
    $topic = SchemaVersionTopic::factory()->publish()->create([
        'device_schema_version_id' => $schemaVersion->id,
        'suffix' => 'telemetry',
        'label' => 'Telemetry',
    ]);
    $parameter = ParameterDefinition::factory()->create([
        'schema_version_topic_id' => $topic->id,
        'key' => 'TotalEnergy',
        'json_path' => 'TotalEnergy',
        'type' => ParameterDataType::Integer,
        'required' => false,
        'is_active' => true,
    ]);
    $device = Device::factory()->create([
        'device_schema_version_id' => $schemaVersion->id,
        'external_id' => '869244041759402-22',
    ]);
    $sourceTopic = 'migration/source/imoni/869244041759402/22/telemetry';

    DeviceSignalBinding::factory()->create([
        'device_id' => $device->id,
        'parameter_definition_id' => $parameter->id,
        'source_topic' => $sourceTopic,
        'source_json_path' => '$.payload.energy',
        'source_adapter' => 'imoni',
        'is_active' => true,
    ]);

    $envelopes = telemetryBroadcastEnvelopes(
        new IncomingTelemetryEnvelope(
            sourceSubject: str_replace('/', '.', $sourceTopic),
            mqttTopic: $sourceTopic,
            payload: ['payload' => ['energy' => 20488680]],
            receivedAt: now(),
        ),
        null,
        true,
    );

    $resolvedEnvelope = $envelopes->first();

    expect($envelopes)->toHaveCount(1)
        ->and($resolvedEnvelope)->toBeInstanceOf(IncomingTelemetryEnvelope::class)
        ->and($resolvedEnvelope?->mqttTopic)->toBe($topic->resolvedTopic($device))
        ->and($resolvedEnvelope?->deviceUuid)->toBe($device->uuid)
        ->and($resolvedEnvelope?->deviceExternalId)->toBe('869244041759402-22')
        ->and($resolvedEnvelope?->payload)->toMatchArray([
            '_meta' => [
                'binding_mode' => 'device_signal',
                'source_topic' => $sourceTopic,
                'source_adapter' => 'imoni',
                'source_subject' => str_replace('/', '.', $sourceTopic),
            ],
            'TotalEnergy' => 20488680,
        ]);
});

/**
 * @param  array{device: Device, topic: SchemaVersionTopic}|null  $resolvedTopic
 * @return Collection<int, IncomingTelemetryEnvelope>
 */
function telemetryBroadcastEnvelopes(
    IncomingTelemetryEnvelope $envelope,
    ?array $resolvedTopic,
    bool $supportsBindingTopic,
): Collection {
    $method = new ReflectionMethod(IngestTelemetryCommand::class, 'resolveTelemetryBroadcastEnvelopes');

    /** @var Collection<int, IncomingTelemetryEnvelope> $envelopes */
    $envelopes = $method->invoke(
        new IngestTelemetryCommand,
        $envelope,
        $resolvedTopic,
        $supportsBindingTopic,
        app(DeviceSignalBindingResolver::class),
    );

    return $envelopes;
}
