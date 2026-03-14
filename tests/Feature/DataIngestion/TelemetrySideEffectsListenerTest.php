<?php

declare(strict_types=1);

use App\Domain\DataIngestion\Contracts\AnalyticsPublisher;
use App\Domain\DataIngestion\Contracts\HotStateStore;
use App\Domain\DataIngestion\Listeners\QueueTelemetryAnalyticsPublishes;
use App\Domain\DataIngestion\Listeners\QueueTelemetryHotStateWrites;
use App\Domain\DataIngestion\Models\IngestionMessage;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Models\DeviceType;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use App\Events\TelemetryReceived;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'cache.default' => 'array',
        'ingestion.side_effects_queue_connection' => 'redis',
        'ingestion.side_effects_queue' => 'telemetry-side-effects',
        'ingestion.publish_analytics' => true,
        'ingestion.publish_invalid_events' => true,
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
                'status' => $ingestionMessage->status->value,
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
 * @return array{device: Device, topic: SchemaVersionTopic, ingestionMessage: IngestionMessage, telemetryLog: DeviceTelemetryLog}
 */
function buildTelemetrySideEffectContext(string $processingState = 'processed', bool $deviceActive = true): array
{
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
        'external_id' => 'sensor-side-effects',
        'is_active' => $deviceActive,
    ]);

    $ingestionMessage = IngestionMessage::factory()->create([
        'organization_id' => $device->organization_id,
        'device_id' => $device->id,
        'device_schema_version_id' => $schemaVersion->id,
        'schema_version_topic_id' => $topic->id,
        'status' => \App\Domain\DataIngestion\Enums\IngestionStatus::Completed,
    ]);

    $telemetryLog = DeviceTelemetryLog::factory()
        ->forDevice($device)
        ->forTopic($topic)
        ->create([
            'ingestion_message_id' => $ingestionMessage->id,
            'processing_state' => $processingState,
            'transformed_values' => [
                'temp_c' => 21.5,
                'temp_f' => 70.7,
            ],
            'validation_errors' => $processingState === 'invalid'
                ? ['temp_c' => [['message' => 'The temp_c field is required.', 'is_critical' => true]]]
                : null,
        ]);

    return [
        'device' => $device,
        'topic' => $topic,
        'ingestionMessage' => $ingestionMessage,
        'telemetryLog' => $telemetryLog,
    ];
}

it('writes hot state for processed ingestion telemetry on the side effects queue', function (): void {
    $context = buildTelemetrySideEffectContext('processed');

    app(QueueTelemetryHotStateWrites::class)->handle(new TelemetryReceived($context['telemetryLog']));

    expect($this->fakeHotStateStore->writes)->toHaveCount(1)
        ->and($this->fakeHotStateStore->writes[0])->toMatchArray([
            'device_uuid' => $context['device']->uuid,
            'topic_key' => $context['topic']->key,
            'ingestion_message_id' => $context['ingestionMessage']->id,
            'status' => 'completed',
        ]);
});

it('skips hot state writes for telemetry outside the ingestion pipeline', function (): void {
    $context = buildTelemetrySideEffectContext('processed');

    $context['telemetryLog']->update([
        'ingestion_message_id' => null,
    ]);

    app(QueueTelemetryHotStateWrites::class)->handle(new TelemetryReceived($context['telemetryLog']->fresh()));

    expect($this->fakeHotStateStore->writes)->toHaveCount(0);
});

it('publishes analytics for processed ingestion telemetry', function (): void {
    $context = buildTelemetrySideEffectContext('processed');

    app(QueueTelemetryAnalyticsPublishes::class)->handle(new TelemetryReceived($context['telemetryLog']));

    expect($this->fakePublisher->telemetryPublishes)->toHaveCount(1)
        ->and($this->fakePublisher->telemetryPublishes[0])->toMatchArray([
            'device_uuid' => $context['device']->uuid,
            'topic_key' => $context['topic']->key,
            'ingestion_message_id' => $context['ingestionMessage']->id,
        ])
        ->and($this->fakePublisher->invalidPublishes)->toHaveCount(0);
});

it('publishes invalid analytics events for active invalid telemetry', function (): void {
    $context = buildTelemetrySideEffectContext('invalid');

    app(QueueTelemetryAnalyticsPublishes::class)->handle(new TelemetryReceived($context['telemetryLog']));

    expect($this->fakePublisher->telemetryPublishes)->toHaveCount(0)
        ->and($this->fakePublisher->invalidPublishes)->toHaveCount(1)
        ->and($this->fakePublisher->invalidPublishes[0])->toMatchArray([
            'device_uuid' => $context['device']->uuid,
            'topic_key' => $context['topic']->key,
            'ingestion_message_id' => $context['ingestionMessage']->id,
        ]);
});

it('does not publish analytics when the analytics kill switch is off', function (): void {
    config()->set('ingestion.publish_analytics', false);

    $context = buildTelemetrySideEffectContext('processed');

    app(QueueTelemetryAnalyticsPublishes::class)->handle(new TelemetryReceived($context['telemetryLog']));

    expect($this->fakePublisher->telemetryPublishes)->toHaveCount(0)
        ->and($this->fakePublisher->invalidPublishes)->toHaveCount(0);
});

it('configures telemetry side effect listeners for the dedicated side effects queue', function (): void {
    $hotStateListener = app(QueueTelemetryHotStateWrites::class);
    $analyticsListener = app(QueueTelemetryAnalyticsPublishes::class);

    expect($hotStateListener)->toBeInstanceOf(ShouldQueue::class)
        ->and($hotStateListener->viaConnection())->toBe('redis')
        ->and($hotStateListener->viaQueue())->toBe('telemetry-side-effects')
        ->and($analyticsListener)->toBeInstanceOf(ShouldQueue::class)
        ->and($analyticsListener->viaConnection())->toBe('redis')
        ->and($analyticsListener->viaQueue())->toBe('telemetry-side-effects');
});
