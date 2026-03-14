<?php

declare(strict_types=1);

use App\Domain\Automation\Contracts\TriggerMatcher;
use App\Domain\Automation\Jobs\StartAutomationRunFromTelemetry;
use App\Domain\Automation\Listeners\QueueTelemetryAutomationRuns;
use App\Domain\Shared\Services\RuntimeSettingManager;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use App\Events\TelemetryReceived;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('queues automation run jobs for matched workflow versions', function (): void {
    Queue::fake();

    $telemetryLog = DeviceTelemetryLog::factory()->create();

    app()->bind(TriggerMatcher::class, fn () => new class implements TriggerMatcher
    {
        public function hasCandidateTelemetryTriggers(DeviceTelemetryLog $telemetryLog): bool
        {
            return true;
        }

        public function matchTelemetryTriggers(DeviceTelemetryLog $telemetryLog): Collection
        {
            return collect([101, 202]);
        }
    });

    app(QueueTelemetryAutomationRuns::class)->handle(new TelemetryReceived($telemetryLog));

    Queue::assertPushed(StartAutomationRunFromTelemetry::class, 2);
    Queue::assertPushed(StartAutomationRunFromTelemetry::class, function (StartAutomationRunFromTelemetry $job) use ($telemetryLog): bool {
        return (string) $job->telemetryLogId === (string) $telemetryLog->getKey()
            && is_string($job->eventCorrelationId)
            && $job->eventCorrelationId !== '';
    });
});

it('does not queue automation runs when no workflow versions match', function (): void {
    Queue::fake();

    $telemetryLog = DeviceTelemetryLog::factory()->create();

    app()->bind(TriggerMatcher::class, fn () => new class implements TriggerMatcher
    {
        public function hasCandidateTelemetryTriggers(DeviceTelemetryLog $telemetryLog): bool
        {
            return true;
        }

        public function matchTelemetryTriggers(DeviceTelemetryLog $telemetryLog): Collection
        {
            return collect();
        }
    });

    app(QueueTelemetryAutomationRuns::class)->handle(new TelemetryReceived($telemetryLog));

    Queue::assertNothingPushed();
});

it('does not queue automation runs when automation pipeline is disabled', function (): void {
    Queue::fake();

    config()->set('automation.enabled', false);

    $telemetryLog = DeviceTelemetryLog::factory()->create();

    app()->bind(TriggerMatcher::class, fn () => new class implements TriggerMatcher
    {
        public function hasCandidateTelemetryTriggers(DeviceTelemetryLog $telemetryLog): bool
        {
            return true;
        }

        public function matchTelemetryTriggers(DeviceTelemetryLog $telemetryLog): Collection
        {
            return collect([101]);
        }
    });

    app(QueueTelemetryAutomationRuns::class)->handle(new TelemetryReceived($telemetryLog));

    Queue::assertNothingPushed();
});

it('does not queue automation runs when telemetry automation fan-out is disabled', function (): void {
    Queue::fake();

    app(RuntimeSettingManager::class)->setGlobalOverrides([
        'automation.pipeline.telemetry_fanout' => false,
    ]);

    $telemetryLog = DeviceTelemetryLog::factory()->create();

    app()->bind(TriggerMatcher::class, fn () => new class implements TriggerMatcher
    {
        public function hasCandidateTelemetryTriggers(DeviceTelemetryLog $telemetryLog): bool
        {
            return true;
        }

        public function matchTelemetryTriggers(DeviceTelemetryLog $telemetryLog): Collection
        {
            return collect([101]);
        }
    });

    app(QueueTelemetryAutomationRuns::class)->handle(new TelemetryReceived($telemetryLog));

    Queue::assertNothingPushed();
});

it('still queues automation runs when dashboard realtime broadcast is disabled', function (): void {
    Queue::fake();

    app(RuntimeSettingManager::class)->setGlobalOverrides([
        'ingestion.pipeline.broadcast_realtime' => false,
    ]);

    $telemetryLog = DeviceTelemetryLog::factory()->create();

    app()->bind(TriggerMatcher::class, fn () => new class implements TriggerMatcher
    {
        public function hasCandidateTelemetryTriggers(DeviceTelemetryLog $telemetryLog): bool
        {
            return true;
        }

        public function matchTelemetryTriggers(DeviceTelemetryLog $telemetryLog): Collection
        {
            return collect([101]);
        }
    });

    app(QueueTelemetryAutomationRuns::class)->handle(new TelemetryReceived($telemetryLog));

    Queue::assertPushed(StartAutomationRunFromTelemetry::class, 1);
});

it('still broadcasts dashboard realtime channels when telemetry automation fan-out is disabled', function (): void {
    app(RuntimeSettingManager::class)->setGlobalOverrides([
        'automation.pipeline.telemetry_fanout' => false,
    ]);

    $telemetryLog = DeviceTelemetryLog::factory()->create();

    $event = new TelemetryReceived($telemetryLog->fresh(['device']));

    expect($event->broadcastOn())->not->toBe([]);
});

it('avoids queueing the listener when telemetry has no candidate automation scopes', function (): void {
    $telemetryLog = DeviceTelemetryLog::factory()->create();

    app()->bind(TriggerMatcher::class, fn () => new class implements TriggerMatcher
    {
        public function hasCandidateTelemetryTriggers(DeviceTelemetryLog $telemetryLog): bool
        {
            return false;
        }

        public function matchTelemetryTriggers(DeviceTelemetryLog $telemetryLog): Collection
        {
            throw new RuntimeException('matchTelemetryTriggers should not be called when no candidates exist.');
        }
    });

    $listener = app(QueueTelemetryAutomationRuns::class);

    expect($listener->shouldQueue(new TelemetryReceived($telemetryLog)))->toBeFalse();
});

it('rate limits repeated automation dispatches for the same workflow device and topic', function (): void {
    Queue::fake();

    config()->set('automation.telemetry_dispatch_cooldown_seconds', 30);

    $telemetryLog = DeviceTelemetryLog::factory()->create();

    app()->bind(TriggerMatcher::class, fn () => new class implements TriggerMatcher
    {
        public function hasCandidateTelemetryTriggers(DeviceTelemetryLog $telemetryLog): bool
        {
            return true;
        }

        public function matchTelemetryTriggers(DeviceTelemetryLog $telemetryLog): Collection
        {
            return collect([101]);
        }
    });

    $listener = app(QueueTelemetryAutomationRuns::class);

    $listener->handle(new TelemetryReceived($telemetryLog));
    $listener->handle(new TelemetryReceived($telemetryLog));

    Queue::assertPushed(StartAutomationRunFromTelemetry::class, 1);
});

it('configures the telemetry automation listener for the automation queue', function (): void {
    config()->set('automation.queue_connection', 'redis');
    config()->set('automation.queue', 'automation');

    $listener = app(QueueTelemetryAutomationRuns::class);

    expect(app('events')->hasListeners(TelemetryReceived::class))->toBeTrue()
        ->and($listener)->toBeInstanceOf(ShouldQueue::class)
        ->and($listener->viaConnection())->toBe('redis')
        ->and($listener->viaQueue())->toBe('automation');
});
