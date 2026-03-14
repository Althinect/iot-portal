<?php

declare(strict_types=1);

namespace App\Domain\Automation\Listeners;

use App\Domain\Automation\Contracts\TriggerMatcher;
use App\Domain\Automation\Jobs\StartAutomationRunFromTelemetry;
use App\Domain\Automation\Services\TelemetryAutomationDispatchThrottle;
use App\Domain\Shared\Services\RuntimeSettingManager;
use App\Events\TelemetryReceived;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Log\LogManager;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

class QueueTelemetryAutomationRuns implements ShouldQueue
{
    public function __construct(
        private readonly TriggerMatcher $triggerMatcher,
        private readonly TelemetryAutomationDispatchThrottle $dispatchThrottle,
        private readonly LogManager $logManager,
        private readonly RuntimeSettingManager $runtimeSettingManager,
    ) {}

    public function shouldQueue(TelemetryReceived $event): bool
    {
        if (! (bool) config('automation.enabled', true)) {
            return false;
        }

        if (! $this->runtimeSettingManager->booleanValue('automation.pipeline.telemetry_fanout', $event->telemetryLog->device?->organization_id)) {
            return false;
        }

        return $this->triggerMatcher->hasCandidateTelemetryTriggers($event->telemetryLog);
    }

    public function handle(TelemetryReceived $event): void
    {
        if (! $this->shouldQueue($event)) {
            return;
        }

        $eventCorrelationId = (string) Str::uuid();
        $telemetryLog = $event->telemetryLog;
        $workflowVersionIds = $this->triggerMatcher->matchTelemetryTriggers($event->telemetryLog);
        $telemetryLogId = $this->resolveKeyAsString($telemetryLog->getKey());

        if ($telemetryLogId === null) {
            $this->log()->warning('Automation telemetry event skipped because telemetry id could not be resolved.', [
                'device_id' => $telemetryLog->device_id,
                'schema_version_topic_id' => $telemetryLog->schema_version_topic_id,
            ]);

            return;
        }

        if ($workflowVersionIds->isEmpty()) {
            return;
        }

        foreach ($workflowVersionIds as $workflowVersionId) {
            $resolvedWorkflowVersionId = (int) $workflowVersionId;

            if (! $this->dispatchThrottle->shouldDispatch($resolvedWorkflowVersionId, $telemetryLog)) {
                continue;
            }

            StartAutomationRunFromTelemetry::dispatch(
                workflowVersionId: $resolvedWorkflowVersionId,
                telemetryLogId: $telemetryLogId,
                eventCorrelationId: $eventCorrelationId,
            );
        }
    }

    private function log(): LoggerInterface
    {
        $configuredChannel = config('automation.log_channel', 'automation_pipeline');
        $logChannel = is_string($configuredChannel) && $configuredChannel !== ''
            ? $configuredChannel
            : 'automation_pipeline';

        return $this->logManager->channel($logChannel);
    }

    public function viaConnection(): string
    {
        $queueConnection = config('automation.queue_connection', config('queue.default', 'database'));

        return is_string($queueConnection) && $queueConnection !== ''
            ? $queueConnection
            : 'database';
    }

    public function viaQueue(): string
    {
        $queue = config('automation.queue', 'automation');

        return is_string($queue) && $queue !== ''
            ? $queue
            : 'automation';
    }

    private function resolveKeyAsString(mixed $value): ?string
    {
        if (is_int($value) || is_string($value)) {
            $resolved = (string) $value;

            return $resolved !== '' ? $resolved : null;
        }

        return null;
    }
}
