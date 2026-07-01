<?php

declare(strict_types=1);

use App\Domain\DataIngestion\DTO\IncomingTelemetryEnvelope;
use App\Domain\DataIngestion\Enums\IngestionStatus;
use App\Domain\DataIngestion\Models\IngestionMessage;
use App\Domain\DataIngestion\Services\TelemetryIngestionService;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Models\ProfileDerivedParameterDefinition;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use App\Domain\DeviceProfile\Services\ProfileChannelResolver;
use App\Domain\Shared\Services\RuntimeSettingManager;
use App\Domain\Telemetry\Enums\ValidationStatus;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use App\Events\TelemetryReceived;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'broadcasting.default' => 'null',
        'cache.default' => 'array',
        'ingestion.enabled' => true,
        'ingestion.driver' => 'laravel',
        'ingestion.publish_analytics' => true,
        'ingestion.publish_invalid_events' => true,
        'ingestion.stage_log_mode' => 'failures',
        'ingestion.stage_log_sample_rate' => 0.0,
        'ingestion.capture_stage_snapshots' => true,
        'ingestion.capture_success_stage_snapshots' => false,
        'iot.presence.write_throttle_seconds' => 0,
    ]);
    Event::fake([TelemetryReceived::class]);
});

/**
 * @return array{device: Device, channel: DeviceChannel, mqtt_topic: string}
 */
function buildTelemetryContext(bool $active = true): array
{
    $profileVersion = DeviceProfileVersion::factory()->active()->mqtt()->create([
        'protocol_config' => [
            'broker_host' => 'localhost',
            'broker_port' => 1883,
            'username' => null,
            'password' => null,
            'use_tls' => false,
            'base_topic' => 'devices',
        ],
    ]);

    $channel = DeviceChannel::factory()->telemetry()->create([
        'device_profile_version_id' => $profileVersion->id,
        'key' => 'telemetry',
        'address' => 'telemetry',
    ]);

    ProfileParameterDefinition::factory()->create([
        'device_channel_id' => $channel->id,
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

    ProfileDerivedParameterDefinition::factory()->create([
        'device_profile_version_id' => $profileVersion->id,
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

    $device = Device::factory()->create([
        'device_profile_version_id' => $profileVersion->id,
        'external_id' => 'sensor-01',
        'is_active' => $active,
    ]);

    return [
        'device' => $device,
        'channel' => $channel,
        'mqtt_topic' => "devices/{$device->external_id}/{$channel->address}",
    ];
}

it('processes valid telemetry without persisting successful stage logs by default', function (): void {
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

    expect($message->stageLogs)->toHaveCount(0)
        ->and($message->organization_id)->toBe($context['device']->organization_id)
        ->and($message->device_id)->toBe($context['device']->id)
        ->and($message->device_profile_version_id)->toBe($context['device']->device_profile_version_id)
        ->and($message->device_channel_id)->toBe($context['channel']->id);

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

    Event::assertDispatched(TelemetryReceived::class, 1);
});

it('serializes telemetry received events with scalar telemetry identity only', function (): void {
    $telemetryLog = DeviceTelemetryLog::factory()
        ->create()
        ->load(['device', 'channel', 'ingestionMessage']);

    $event = new TelemetryReceived($telemetryLog);
    $serialized = serialize($event);

    expect($event->telemetryLogId)->toBe($telemetryLog->id)
        ->and($event->telemetryLog()?->is($telemetryLog))->toBeTrue()
        ->and($serialized)->not->toContain(DeviceTelemetryLog::class)
        ->and($serialized)->not->toContain('device_channel_id')
        ->and($serialized)->not->toContain('transformed_values');
});

it('persists telemetry and still dispatches downstream side effects when analytics publishing is disabled', function (): void {
    app(RuntimeSettingManager::class)->setGlobalOverrides([
        'ingestion.pipeline.publish_analytics' => false,
    ]);

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

    Event::assertDispatched(TelemetryReceived::class, 1);
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

    expect($message->stageLogs)->toHaveCount(1)
        ->and($message->organization_id)->toBe($context['device']->organization_id)
        ->and($message->device_id)->toBe($context['device']->id)
        ->and($message->device_profile_version_id)->toBe($context['device']->device_profile_version_id)
        ->and($message->device_channel_id)->toBe($context['channel']->id);

    $validationStage = $message->stageLogs()->where('stage', 'persist')->first();

    expect($validationStage)->not->toBeNull()
        ->and($validationStage?->output_snapshot)->not->toBeNull();

    $telemetryLog = $message->telemetryLog()->first();

    expect($telemetryLog)->not->toBeNull()
        ->and($telemetryLog?->processing_state)->toBe('invalid')
        ->and($telemetryLog?->validation_errors)->toHaveKey('temp_c')
        ->and($telemetryLog?->mutated_values)->toBeNull();

    Event::assertDispatched(TelemetryReceived::class, 1);
});

it('continues mutation and derivation when validation only has non-critical warnings', function (): void {
    $context = buildTelemetryContext(true);

    ProfileParameterDefinition::factory()->create([
        'device_channel_id' => $context['channel']->id,
        'key' => 'power_factor',
        'json_path' => 'power_factor',
        'type' => ParameterDataType::Decimal,
        'required' => false,
        'is_critical' => false,
        'mutation_expression' => null,
        'validation_rules' => [
            'min' => 0,
            'max' => 1,
        ],
        'sequence' => 2,
        'is_active' => true,
    ]);

    /** @var TelemetryIngestionService $service */
    $service = app(TelemetryIngestionService::class);

    $message = $service->ingest(new IncomingTelemetryEnvelope(
        sourceSubject: str_replace('/', '.', $context['mqtt_topic']),
        mqttTopic: $context['mqtt_topic'],
        payload: [
            'temp_c' => 10,
            'power_factor' => 797,
        ],
        deviceExternalId: 'sensor-01',
        receivedAt: now(),
    ));

    expect($message)->toBeInstanceOf(IngestionMessage::class)
        ->and($message?->status)->toBe(IngestionStatus::Completed);

    $telemetryLog = $message?->telemetryLog()->first();

    expect($telemetryLog)->not->toBeNull()
        ->and($telemetryLog?->processing_state)->toBe('processed')
        ->and($telemetryLog?->validation_status)->toBe(ValidationStatus::Warning)
        ->and($telemetryLog?->validation_errors)->toHaveKey('power_factor')
        ->and($telemetryLog?->mutated_values)->toMatchArray([
            'temp_c' => 12.0,
            'power_factor' => 797,
        ])
        ->and($telemetryLog?->transformed_values)->toMatchArray([
            'temp_c' => 12.0,
            'temp_f' => 53.6,
            'power_factor' => 797,
        ]);

    Event::assertDispatched(TelemetryReceived::class, 1);
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

    expect($message->stageLogs)->toHaveCount(1);

    $telemetryLog = $message->telemetryLog()->first();

    expect($telemetryLog)->not->toBeNull()
        ->and($telemetryLog?->processing_state)->toBe('inactive_skipped')
        ->and($telemetryLog?->mutated_values)->toBeNull();

    Event::assertDispatched(TelemetryReceived::class, 1);
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
        ->and($telemetryLogCount)->toBe(1);

    Event::assertDispatched(TelemetryReceived::class, 1);
});

it('keeps happy-path ingestion within the expected query budget', function (): void {
    $context = buildTelemetryContext(true);

    app(ProfileChannelResolver::class)->resolve($context['mqtt_topic']);

    /** @var TelemetryIngestionService $service */
    $service = app(TelemetryIngestionService::class);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $message = $service->ingest(new IncomingTelemetryEnvelope(
        sourceSubject: str_replace('/', '.', $context['mqtt_topic']),
        mqttTopic: $context['mqtt_topic'],
        payload: ['temp_c' => 10],
        messageId: 'query-budget-1',
        deviceExternalId: 'sensor-01',
        receivedAt: now(),
    ));

    expect($message)->toBeInstanceOf(IngestionMessage::class)
        ->and($message?->status)->toBe(IngestionStatus::Completed);

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(8);
});

it('skips presence writes within the heartbeat throttle window', function (): void {
    config()->set('iot.presence.write_throttle_seconds', 30);

    $context = buildTelemetryContext(true);

    /** @var TelemetryIngestionService $service */
    $service = app(TelemetryIngestionService::class);

    $service->ingest(new IncomingTelemetryEnvelope(
        sourceSubject: str_replace('/', '.', $context['mqtt_topic']),
        mqttTopic: $context['mqtt_topic'],
        payload: ['temp_c' => 10],
        messageId: 'query-budget-2',
        deviceExternalId: 'sensor-01',
        receivedAt: now(),
    ));

    DB::flushQueryLog();
    DB::enableQueryLog();

    $message = $service->ingest(new IncomingTelemetryEnvelope(
        sourceSubject: str_replace('/', '.', $context['mqtt_topic']),
        mqttTopic: $context['mqtt_topic'],
        payload: ['temp_c' => 11],
        messageId: 'query-budget-3',
        deviceExternalId: 'sensor-01',
        receivedAt: now()->addSeconds(10),
    ));

    expect($message)->toBeInstanceOf(IngestionMessage::class)
        ->and($message?->status)->toBe(IngestionStatus::Completed);

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(5);
});

it('handles duplicate envelopes with the conflict-first query budget', function (): void {
    Event::fake([TelemetryReceived::class]);

    $context = buildTelemetryContext(true);

    /** @var TelemetryIngestionService $service */
    $service = app(TelemetryIngestionService::class);

    $envelope = new IncomingTelemetryEnvelope(
        sourceSubject: str_replace('/', '.', $context['mqtt_topic']),
        mqttTopic: $context['mqtt_topic'],
        payload: ['temp_c' => 10],
        messageId: 'query-budget-duplicate',
        deviceExternalId: 'sensor-01',
        receivedAt: now(),
    );

    $service->ingest($envelope);

    $this->expectsDatabaseQueryCount(3);

    $message = $service->ingest($envelope);

    expect($message)->toBeInstanceOf(IngestionMessage::class)
        ->and($message?->status)->toBe(IngestionStatus::Duplicate);
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
        ->and(IngestionMessage::query()->count())->toBe(2);

    Event::assertDispatchedTimes(TelemetryReceived::class, 2);
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
        ->and(IngestionMessage::query()->count())->toBe(0);

    Event::assertNotDispatched(TelemetryReceived::class);
});

it('keeps ingestion completed and records a failed publish stage when downstream dispatch throws', function (): void {
    $context = buildTelemetryContext(true);
    Event::swap(new class(app()) extends Dispatcher
    {
        public function dispatch($event, $payload = [], $halt = false): ?array
        {
            if ($event instanceof TelemetryReceived) {
                throw new RuntimeException('side_effect_dispatch_failed');
            }

            return parent::dispatch($event, $payload, $halt);
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
        ->and($message?->status)->toBe(IngestionStatus::Completed);

    $message->refresh();

    expect($message->error_summary)->toBeNull();

    $telemetryLog = $message->telemetryLog()->first();

    expect($telemetryLog)->not->toBeNull()
        ->and($telemetryLog?->processing_state)->toBe('processed');

    $publishStage = $message->stageLogs()->where('stage', 'publish')->latest('id')->first();

    expect($publishStage)->not->toBeNull()
        ->and($publishStage?->status)->toBe(IngestionStatus::FailedTerminal)
        ->and($publishStage?->errors)->toHaveKey('side_effect_dispatch');
});

it('persists successful stage logs when stage log mode is all', function (): void {
    config()->set('ingestion.stage_log_mode', 'all');

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

    $persistStage = $message->stageLogs()->where('stage', 'persist')->first();

    expect($message->stageLogs)->toHaveCount(3)
        ->and($persistStage)->not->toBeNull()
        ->and($persistStage?->input_snapshot)->toBeNull()
        ->and($persistStage?->output_snapshot)->toBeNull();
});

it('captures successful stage snapshots only when explicitly enabled', function (): void {
    config()->set('ingestion.stage_log_mode', 'all');
    config()->set('ingestion.capture_success_stage_snapshots', true);

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

    $validateStage = $message->stageLogs()->where('stage', 'persist')->first();

    expect($validateStage)->not->toBeNull()
        ->and($validateStage?->output_snapshot)->not->toBeNull();
});

it('samples successful stage logs when sampled mode is enabled', function (): void {
    config()->set('ingestion.stage_log_mode', 'sampled');
    config()->set('ingestion.stage_log_sample_rate', 1.0);

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

    expect($message->stageLogs)->toHaveCount(3);
});
