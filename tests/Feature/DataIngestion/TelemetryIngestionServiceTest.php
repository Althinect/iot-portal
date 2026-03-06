<?php

declare(strict_types=1);

use App\Domain\DataIngestion\Contracts\AnalyticsPublisher;
use App\Domain\DataIngestion\Contracts\HotStateStore;
use App\Domain\DataIngestion\DTO\IncomingTelemetryEnvelope;
use App\Domain\DataIngestion\Enums\IngestionStatus;
use App\Domain\DataIngestion\Models\IngestionMessage;
use App\Domain\DataIngestion\Services\TelemetryIngestionService;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Models\DeviceType;
use App\Domain\DeviceSchema\Enums\ParameterDataType;
use App\Domain\DeviceSchema\Models\DerivedParameterDefinition;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Domain\DeviceSchema\Models\ParameterDefinition;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'broadcasting.default' => 'null',
        'ingestion.enabled' => true,
        'ingestion.driver' => 'laravel',
        'ingestion.publish_analytics' => true,
        'ingestion.publish_invalid_events' => true,
        'ingestion.capture_stage_snapshots' => true,
        'ingestion.capture_success_stage_snapshots' => false,
        'iot.presence.write_throttle_seconds' => 0,
    ]);

    $this->fakeHotStateStore = new class implements HotStateStore
    {
        /** @var array<int, array<string, mixed>> */
        public array $writes = [];

        public function store(Device $device, SchemaVersionTopic $topic, array $finalValues, IngestionMessage $ingestionMessage): void
        {
            $this->writes[] = [
                'device_uuid' => $device->uuid,
                'topic_key' => $topic->key,
                'values' => $finalValues,
                'ingestion_message_id' => $ingestionMessage->id,
            ];
        }
    };

    $this->fakePublisher = new class implements AnalyticsPublisher
    {
        /** @var array<int, array<string, mixed>> */
        public array $telemetryPublishes = [];

        /** @var array<int, array<string, mixed>> */
        public array $invalidPublishes = [];

        public function publishTelemetry(Device $device, SchemaVersionTopic $topic, array $finalValues, IngestionMessage $ingestionMessage): void
        {
            $this->telemetryPublishes[] = [
                'device_uuid' => $device->uuid,
                'topic_key' => $topic->key,
                'values' => $finalValues,
                'ingestion_message_id' => $ingestionMessage->id,
            ];
        }

        public function publishInvalid(Device $device, SchemaVersionTopic $topic, array $validationErrors, IngestionMessage $ingestionMessage): void
        {
            $this->invalidPublishes[] = [
                'device_uuid' => $device->uuid,
                'topic_key' => $topic->key,
                'errors' => $validationErrors,
                'ingestion_message_id' => $ingestionMessage->id,
            ];
        }
    };

    app()->instance(HotStateStore::class, $this->fakeHotStateStore);
    app()->instance(AnalyticsPublisher::class, $this->fakePublisher);
});

/**
 * @return array{device: Device, topic: SchemaVersionTopic, mqtt_topic: string}
 */
function buildTelemetryContext(bool $active = true): array
{
    $schemaVersion = DeviceSchemaVersion::factory()->create();

    $topic = SchemaVersionTopic::factory()->publish()->create([
        'device_schema_version_id' => $schemaVersion->id,
        'key' => 'telemetry',
        'suffix' => 'telemetry',
    ]);

    ParameterDefinition::factory()->create([
        'schema_version_topic_id' => $topic->id,
        'key' => 'temp_c',
        'json_path' => 'temp_c',
        'type' => ParameterDataType::Decimal,
        'required' => true,
        'is_critical' => true,
        'mutation_expression' => [
            '+' => [
                ['var' => 'val'],
                2,
            ],
        ],
        'sequence' => 1,
        'is_active' => true,
    ]);

    DerivedParameterDefinition::factory()->create([
        'device_schema_version_id' => $schemaVersion->id,
        'key' => 'temp_f',
        'data_type' => ParameterDataType::Decimal,
        'expression' => [
            '+' => [
                [
                    '*' => [
                        ['var' => 'temp_c'],
                        1.8,
                    ],
                ],
                32,
            ],
        ],
        'dependencies' => ['temp_c'],
    ]);

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

    $device = Device::factory()->create([
        'device_type_id' => $deviceType->id,
        'device_schema_version_id' => $schemaVersion->id,
        'external_id' => 'sensor-01',
        'is_active' => $active,
    ]);

    return [
        'device' => $device,
        'topic' => $topic,
        'mqtt_topic' => $topic->resolvedTopic($device),
    ];
}

it('processes valid telemetry through all stages and publishes analytics', function (): void {
    $context = buildTelemetryContext(true);

    /** @var TelemetryIngestionService $service */
    $service = app(TelemetryIngestionService::class);

    $message = $service->ingest(new IncomingTelemetryEnvelope(
        sourceSubject: str_replace('/', '.', $context['mqtt_topic']),
        mqttTopic: $context['mqtt_topic'],
        payload: ['temp_c' => 10],
        deviceExternalId: 'sensor-01',
        receivedAt: now(),
    ));

    expect($message)->toBeInstanceOf(IngestionMessage::class)
        ->and($message?->status)->toBe(IngestionStatus::Completed);

    $message->refresh();

    expect($message->stageLogs)->toHaveCount(6);
    expect($message->organization_id)->toBe($context['device']->organization_id)
        ->and($message->device_id)->toBe($context['device']->id)
        ->and($message->device_schema_version_id)->toBe($context['device']->device_schema_version_id)
        ->and($message->schema_version_topic_id)->toBe($context['topic']->id);

    $successfulStage = $message->stageLogs()->where('stage', 'persist')->first();

    expect($successfulStage)->not->toBeNull()
        ->and($successfulStage?->input_snapshot)->toBeNull()
        ->and($successfulStage?->output_snapshot)->toBeNull()
        ->and($successfulStage?->change_set)->toBeNull();

    $telemetryLog = $message->telemetryLog()->first();

    expect($telemetryLog)->not->toBeNull()
        ->and($telemetryLog?->processing_state)->toBe('processed')
        ->and($telemetryLog?->mutated_values)->toMatchArray([
            'temp_c' => 12.0,
        ])
        ->and($telemetryLog?->transformed_values)->toMatchArray([
            'temp_c' => 12.0,
            'temp_f' => 53.6,
        ]);

    expect($this->fakeHotStateStore->writes)->toHaveCount(1)
        ->and($this->fakePublisher->telemetryPublishes)->toHaveCount(1)
        ->and($this->fakePublisher->invalidPublishes)->toHaveCount(0);
});

it('halts processing on validation failure and publishes invalid telemetry event', function (): void {
    $context = buildTelemetryContext(true);

    /** @var TelemetryIngestionService $service */
    $service = app(TelemetryIngestionService::class);

    $message = $service->ingest(new IncomingTelemetryEnvelope(
        sourceSubject: str_replace('/', '.', $context['mqtt_topic']),
        mqttTopic: $context['mqtt_topic'],
        payload: [],
        deviceExternalId: 'sensor-01',
        receivedAt: now(),
    ));

    expect($message)->toBeInstanceOf(IngestionMessage::class)
        ->and($message?->status)->toBe(IngestionStatus::FailedValidation);

    $message->refresh();

    expect($message->stageLogs)->toHaveCount(2);
    expect($message->organization_id)->toBe($context['device']->organization_id)
        ->and($message->device_id)->toBe($context['device']->id)
        ->and($message->device_schema_version_id)->toBe($context['device']->device_schema_version_id)
        ->and($message->schema_version_topic_id)->toBe($context['topic']->id);

    $validationStage = $message->stageLogs()->where('stage', 'validate')->first();

    expect($validationStage)->not->toBeNull()
        ->and($validationStage?->input_snapshot)->not->toBeNull()
        ->and($validationStage?->output_snapshot)->not->toBeNull();

    $telemetryLog = $message->telemetryLog()->first();

    expect($telemetryLog)->not->toBeNull()
        ->and($telemetryLog?->processing_state)->toBe('invalid')
        ->and($telemetryLog?->validation_errors)->toHaveKey('temp_c')
        ->and($telemetryLog?->mutated_values)->toBeNull();

    expect($this->fakeHotStateStore->writes)->toHaveCount(0)
        ->and($this->fakePublisher->telemetryPublishes)->toHaveCount(0)
        ->and($this->fakePublisher->invalidPublishes)->toHaveCount(1);
});

it('records inactive device telemetry but skips post-validation processing', function (): void {
    $context = buildTelemetryContext(false);

    /** @var TelemetryIngestionService $service */
    $service = app(TelemetryIngestionService::class);

    $message = $service->ingest(new IncomingTelemetryEnvelope(
        sourceSubject: str_replace('/', '.', $context['mqtt_topic']),
        mqttTopic: $context['mqtt_topic'],
        payload: ['temp_c' => 10],
        deviceExternalId: 'sensor-01',
        receivedAt: now(),
    ));

    expect($message)->toBeInstanceOf(IngestionMessage::class)
        ->and($message?->status)->toBe(IngestionStatus::InactiveSkipped);

    $message->refresh();

    expect($message->stageLogs)->toHaveCount(2);

    $telemetryLog = $message->telemetryLog()->first();

    expect($telemetryLog)->not->toBeNull()
        ->and($telemetryLog?->processing_state)->toBe('inactive_skipped')
        ->and($telemetryLog?->mutated_values)->toBeNull();

    expect($this->fakeHotStateStore->writes)->toHaveCount(0)
        ->and($this->fakePublisher->telemetryPublishes)->toHaveCount(0)
        ->and($this->fakePublisher->invalidPublishes)->toHaveCount(0);
});

it('marks duplicate envelopes and prevents duplicate downstream writes', function (): void {
    $context = buildTelemetryContext(true);

    /** @var TelemetryIngestionService $service */
    $service = app(TelemetryIngestionService::class);

    $envelope = new IncomingTelemetryEnvelope(
        sourceSubject: str_replace('/', '.', $context['mqtt_topic']),
        mqttTopic: $context['mqtt_topic'],
        payload: ['temp_c' => 10],
        messageId: 'dedupe-1',
        deviceExternalId: 'sensor-01',
        receivedAt: now(),
    );

    $first = $service->ingest($envelope);
    $second = $service->ingest($envelope);

    expect($first)->toBeInstanceOf(IngestionMessage::class)
        ->and($second)->toBeInstanceOf(IngestionMessage::class)
        ->and($first?->id)->toBe($second?->id)
        ->and($second?->status)->toBe(IngestionStatus::Duplicate);

    $count = IngestionMessage::query()->count();
    $telemetryLogCount = $first?->telemetryLog()->count() ?? 0;

    expect($count)->toBe(1)
        ->and($telemetryLogCount)->toBe(1)
        ->and($this->fakeHotStateStore->writes)->toHaveCount(1)
        ->and($this->fakePublisher->telemetryPublishes)->toHaveCount(1);
});

it('does not deduplicate repeated payloads when transport message id is absent', function (): void {
    $context = buildTelemetryContext(true);

    /** @var TelemetryIngestionService $service */
    $service = app(TelemetryIngestionService::class);

    $first = $service->ingest(new IncomingTelemetryEnvelope(
        sourceSubject: str_replace('/', '.', $context['mqtt_topic']),
        mqttTopic: $context['mqtt_topic'],
        payload: ['temp_c' => 10],
        deviceExternalId: 'sensor-01',
        receivedAt: now(),
    ));

    $second = $service->ingest(new IncomingTelemetryEnvelope(
        sourceSubject: str_replace('/', '.', $context['mqtt_topic']),
        mqttTopic: $context['mqtt_topic'],
        payload: ['temp_c' => 10],
        deviceExternalId: 'sensor-01',
        receivedAt: now()->addSecond(),
    ));

    expect($first)->toBeInstanceOf(IngestionMessage::class)
        ->and($second)->toBeInstanceOf(IngestionMessage::class)
        ->and($first?->id)->not->toBe($second?->id)
        ->and($first?->status)->toBe(IngestionStatus::Completed)
        ->and($second?->status)->toBe(IngestionStatus::Completed)
        ->and(IngestionMessage::query()->count())->toBe(2)
        ->and($this->fakeHotStateStore->writes)->toHaveCount(2)
        ->and($this->fakePublisher->telemetryPublishes)->toHaveCount(2);
});

it('skips ingestion when the pipeline feature is disabled', function (): void {
    config(['ingestion.enabled' => false]);

    $context = buildTelemetryContext(true);

    /** @var TelemetryIngestionService $service */
    $service = app(TelemetryIngestionService::class);

    $message = $service->ingest(new IncomingTelemetryEnvelope(
        sourceSubject: str_replace('/', '.', $context['mqtt_topic']),
        mqttTopic: $context['mqtt_topic'],
        payload: ['temp_c' => 10],
        receivedAt: now(),
    ));

    expect($message)->toBeNull()
        ->and(IngestionMessage::query()->count())->toBe(0)
        ->and($this->fakeHotStateStore->writes)->toHaveCount(0)
        ->and($this->fakePublisher->telemetryPublishes)->toHaveCount(0)
        ->and($this->fakePublisher->invalidPublishes)->toHaveCount(0);
});

it('marks ingestion as failed terminal when post-persist publish side effects fail', function (): void {
    $context = buildTelemetryContext(true);

    app()->instance(HotStateStore::class, new class implements HotStateStore
    {
        public function store(Device $device, SchemaVersionTopic $topic, array $finalValues, IngestionMessage $ingestionMessage): void
        {
            throw new RuntimeException('kv_write_failed');
        }
    });

    /** @var TelemetryIngestionService $service */
    $service = app(TelemetryIngestionService::class);

    $message = $service->ingest(new IncomingTelemetryEnvelope(
        sourceSubject: str_replace('/', '.', $context['mqtt_topic']),
        mqttTopic: $context['mqtt_topic'],
        payload: ['temp_c' => 10],
        deviceExternalId: 'sensor-01',
        receivedAt: now(),
    ));

    expect($message)->toBeInstanceOf(IngestionMessage::class)
        ->and($message?->status)->toBe(IngestionStatus::FailedTerminal);

    $message->refresh();

    expect($message->error_summary)->toMatchArray([
        'reason' => 'publish_failed',
    ])->and($message->error_summary['errors'] ?? [])->toHaveKey('hot_state');

    $telemetryLog = $message->telemetryLog()->first();

    expect($telemetryLog)->not->toBeNull()
        ->and($telemetryLog?->processing_state)->toBe('publish_failed');

    $publishStage = $message->stageLogs()->where('stage', 'publish')->latest('id')->first();

    expect($publishStage)->not->toBeNull()
        ->and($publishStage?->status)->toBe(IngestionStatus::FailedTerminal)
        ->and($publishStage?->errors)->toHaveKey('hot_state');
});
