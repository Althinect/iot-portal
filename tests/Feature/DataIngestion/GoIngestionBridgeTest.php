<?php

declare(strict_types=1);

use App\Console\Commands\Ingestion\ConsumeTelemetryIngestionEvents;
use App\Domain\Automation\Enums\AutomationWorkflowStatus;
use App\Domain\Automation\Jobs\DispatchTelemetryAutomation;
use App\Domain\Automation\Listeners\QueueTelemetryAutomationRuns;
use App\Domain\Automation\Models\AutomationTelemetryTrigger;
use App\Domain\Automation\Models\AutomationWorkflow;
use App\Domain\Automation\Models\AutomationWorkflowVersion;
use App\Domain\Automation\Services\AutomationTelemetryScopeIndex;
use App\Domain\DataIngestion\Jobs\DispatchTelemetryReceivedSideEffects;
use App\Domain\DataIngestion\Models\IngestionMessage;
use App\Domain\Shared\Services\RuntimeSettingRegistry;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use App\Events\TelemetryIncoming;
use App\Events\TelemetryReceived;
use Basis\Nats\Message\Payload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

it('allows go as an ingestion pipeline driver', function (): void {
    $options = app(RuntimeSettingRegistry::class)->options('ingestion.pipeline.driver');

    expect($options)->toHaveKey('laravel')
        ->and($options)->toHaveKey('go');
});

it('uses the configured heartbeat interval for the go ingestion bridge', function (): void {
    config()->set('ingestion.nats.health_check_seconds', 7);

    $command = app(ConsumeTelemetryIngestionEvents::class);
    $reflection = new ReflectionMethod($command, 'resolveHealthCheckInterval');

    expect($reflection->invoke($command))->toBe(7);
});

it('selects the requested go ingestion event streams', function (string $eventMode, bool $consumesIncoming, bool $consumesPersisted): void {
    $command = app(ConsumeTelemetryIngestionEvents::class);
    $incomingReflection = new ReflectionMethod($command, 'consumesIncomingEvents');
    $persistedReflection = new ReflectionMethod($command, 'consumesPersistedEvents');

    expect($incomingReflection->invoke($command, $eventMode))->toBe($consumesIncoming)
        ->and($persistedReflection->invoke($command, $eventMode))->toBe($consumesPersisted);
})->with([
    'all events' => ['all', true, true],
    'incoming events only' => ['incoming', true, false],
    'persisted events only' => ['persisted', false, true],
]);

it('rejects an invalid go ingestion event mode', function (): void {
    $this->artisan('ingestion:consume-go-events', ['--only' => 'unknown'])
        ->expectsOutputToContain('The --only option must be incoming, persisted, or all.')
        ->assertExitCode(2);
});

it('rejects an invalid go ingestion effects mode', function (): void {
    $this->artisan('ingestion:consume-go-events', ['--effects' => 'unknown'])
        ->expectsOutputToContain('The --effects option must be automation or all.')
        ->assertExitCode(2);
});

it('dispatches raw telemetry incoming events from go bridge payloads', function (): void {
    Event::fake([TelemetryIncoming::class]);

    $command = app(ConsumeTelemetryIngestionEvents::class);
    invokeBridgeHandler($command, 'handleIncomingPayload', new Payload(json_encode([
        'topic' => 'devices/sensor-01/telemetry',
        'device_uuid' => 'device-uuid',
        'device_external_id' => 'sensor-01',
        'payload' => ['temp_c' => 24.5],
        'received_at' => '2026-07-01T12:00:00Z',
    ], JSON_THROW_ON_ERROR), subject: 'iot.v1.ingestion.incoming'));

    Event::assertDispatched(TelemetryIncoming::class, function (TelemetryIncoming $event): bool {
        return $event->topic === 'devices/sensor-01/telemetry'
            && $event->deviceUuid === 'device-uuid'
            && $event->deviceExternalId === 'sensor-01'
            && $event->payload === ['temp_c' => 24.5];
    });
});

it('queues telemetry side effects from go persisted payloads', function (): void {
    Queue::fake();

    $ingestionMessage = IngestionMessage::factory()->create();
    $telemetryLog = DeviceTelemetryLog::factory()->create([
        'device_id' => $ingestionMessage->device_id,
        'device_profile_version_id' => $ingestionMessage->device_profile_version_id,
        'device_channel_id' => $ingestionMessage->device_channel_id,
        'ingestion_message_id' => $ingestionMessage->id,
    ]);

    $command = app(ConsumeTelemetryIngestionEvents::class);
    $scopeIndex = mock(AutomationTelemetryScopeIndex::class);
    $scopeIndex->shouldReceive('hasCandidate')
        ->once()
        ->with((int) $telemetryLog->device_id, (int) $telemetryLog->device_channel_id)
        ->andReturnTrue();

    invokeBridgeHandler($command, 'handlePersistedPayload', new Payload(json_encode([
        'telemetry_log_id' => $telemetryLog->id,
        'ingestion_message_id' => $ingestionMessage->id,
        'device_id' => $telemetryLog->device_id,
        'device_channel_id' => $telemetryLog->device_channel_id,
    ], JSON_THROW_ON_ERROR), subject: 'iot.v1.ingestion.persisted'), $scopeIndex, 'all');

    Queue::assertPushed(DispatchTelemetryReceivedSideEffects::class, function (DispatchTelemetryReceivedSideEffects $job) use ($telemetryLog): bool {
        return $job->telemetryLogId === $telemetryLog->id
            && $job->connection === 'redis'
            && $job->queue === 'telemetry-side-effects';
    });
    Queue::assertPushed(DispatchTelemetryAutomation::class, function (DispatchTelemetryAutomation $job) use ($telemetryLog): bool {
        return $job->telemetryLogId === $telemetryLog->id
            && $job->connection === 'redis'
            && $job->queue === 'automation';
    });
});

it('queues only candidate automation in production effects mode', function (): void {
    Queue::fake();

    $scopeIndex = mock(AutomationTelemetryScopeIndex::class);
    $scopeIndex->shouldReceive('hasCandidate')
        ->once()
        ->with(17, 4)
        ->andReturnTrue();

    $command = app(ConsumeTelemetryIngestionEvents::class);
    invokeBridgeHandler($command, 'handlePersistedPayload', new Payload(json_encode([
        'telemetry_log_id' => 'telemetry-log-id',
        'device_id' => 17,
        'device_channel_id' => 4,
    ], JSON_THROW_ON_ERROR), subject: 'iot.v1.ingestion.persisted'), $scopeIndex, 'automation');

    Queue::assertPushed(DispatchTelemetryAutomation::class, 1);
    Queue::assertNotPushed(DispatchTelemetryReceivedSideEffects::class);
});

it('indexes active telemetry automation scopes and refreshes after trigger changes', function (): void {
    $telemetryLog = DeviceTelemetryLog::factory()->create();
    $workflow = AutomationWorkflow::factory()->create([
        'organization_id' => $telemetryLog->device->organization_id,
        'status' => AutomationWorkflowStatus::Active,
    ]);
    $workflowVersion = AutomationWorkflowVersion::factory()->create([
        'automation_workflow_id' => $workflow->id,
    ]);
    $workflow->update(['active_version_id' => $workflowVersion->id]);

    AutomationTelemetryTrigger::factory()->create([
        'organization_id' => $workflow->organization_id,
        'workflow_version_id' => $workflowVersion->id,
        'device_id' => $telemetryLog->device_id,
        'device_channel_id' => $telemetryLog->device_channel_id,
    ]);

    $scopeIndex = app(AutomationTelemetryScopeIndex::class);

    expect($scopeIndex->hasCandidate((int) $telemetryLog->device_id, (int) $telemetryLog->device_channel_id))->toBeTrue()
        ->and($scopeIndex->hasCandidate((int) $telemetryLog->device_id + 1, (int) $telemetryLog->device_channel_id))->toBeFalse();

    AutomationTelemetryTrigger::factory()->create([
        'organization_id' => $workflow->organization_id,
        'workflow_version_id' => $workflowVersion->id,
        'device_id' => null,
        'device_channel_id' => null,
    ]);

    expect($scopeIndex->hasCandidate((int) $telemetryLog->device_id + 1, (int) $telemetryLog->device_channel_id + 1))->toBeTrue();
});

it('dispatches telemetry received events from the queued go side effects bridge', function (): void {
    Event::fake([TelemetryReceived::class]);

    $job = new DispatchTelemetryReceivedSideEffects('telemetry-log-id');
    $job->handle();

    Event::assertDispatched(TelemetryReceived::class, function (TelemetryReceived $event): bool {
        return $event->telemetryLogId === 'telemetry-log-id'
            && $event->skipAutomation;
    });
});

it('dispatches go telemetry automation through the priority bridge', function (): void {
    $listener = mock(QueueTelemetryAutomationRuns::class);
    $listener->shouldReceive('handle')
        ->once()
        ->withArgs(function (TelemetryReceived $event): bool {
            return $event->telemetryLogId === 'telemetry-log-id'
                && ! $event->skipAutomation;
        });

    $job = new DispatchTelemetryAutomation('telemetry-log-id');
    $job->handle($listener);
});

function invokeBridgeHandler(ConsumeTelemetryIngestionEvents $command, string $method, mixed ...$arguments): void
{
    $reflection = new ReflectionMethod($command, $method);
    $reflection->invoke($command, ...$arguments);
}
