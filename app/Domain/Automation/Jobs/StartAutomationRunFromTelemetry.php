<?php

declare(strict_types=1);

namespace App\Domain\Automation\Jobs;

use App\Domain\Automation\Enums\AutomationRunStatus;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Automation\Models\AutomationWorkflowVersion;
use App\Domain\Automation\Services\WorkflowRunExecutor;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

class StartAutomationRunFromTelemetry implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $workflowVersionId,
        public readonly int|string $telemetryLogId,
        public readonly ?string $eventCorrelationId = null,
    ) {
        $queueConnection = config('automation.queue_connection', config('queue.default', 'database'));
        $queue = config('automation.queue', 'automation');

        $resolvedConnection = is_string($queueConnection) && $queueConnection !== '' ? $queueConnection : 'database';
        $resolvedQueue = is_string($queue) && $queue !== '' ? $queue : 'automation';

        $this->onConnection($resolvedConnection);
        $this->onQueue($resolvedQueue);
    }

    public function handle(): void
    {
        $eventCorrelationId = $this->resolveEventCorrelationId();
        $runCorrelationId = (string) Str::uuid();

        $baseLogContext = [
            'event_correlation_id' => $eventCorrelationId,
            'run_correlation_id' => $runCorrelationId,
            'workflow_version_id' => $this->workflowVersionId,
            'telemetry_log_id' => (string) $this->telemetryLogId,
        ];

        $workflowVersion = AutomationWorkflowVersion::query()
            ->with('workflow')
            ->find($this->workflowVersionId);

        $telemetryLog = DeviceTelemetryLog::query()->find((string) $this->telemetryLogId);

        if ($workflowVersion === null || $workflowVersion->workflow === null || $telemetryLog === null) {
            $this->log()->warning('Automation run job aborted due to missing workflow version or telemetry log.', [
                ...$baseLogContext,
                'workflow_version_found' => $workflowVersion !== null,
                'workflow_found' => $workflowVersion?->workflow !== null,
                'telemetry_log_found' => $telemetryLog !== null,
            ]);

            return;
        }

        $workflow = $workflowVersion->workflow;

        $run = AutomationRun::query()->create([
            'organization_id' => $workflow->organization_id,
            'workflow_id' => $workflow->id,
            'workflow_version_id' => $workflowVersion->id,
            'trigger_type' => 'telemetry',
            'trigger_payload' => [
                'telemetry_log_id' => $telemetryLog->id,
                'device_id' => $telemetryLog->device_id,
                'schema_version_topic_id' => $telemetryLog->schema_version_topic_id,
                'event_correlation_id' => $eventCorrelationId,
                'run_correlation_id' => $runCorrelationId,
            ],
            'status' => AutomationRunStatus::Running,
            'started_at' => now(),
        ]);

        try {
            $result = app(WorkflowRunExecutor::class)->executeTelemetryRun(
                run: $run,
                workflowVersion: $workflowVersion,
                telemetryLog: $telemetryLog,
                runCorrelationId: $runCorrelationId,
            );

            $run->forceFill([
                'status' => $result->status,
                'finished_at' => now(),
                'error_summary' => $result->error,
            ])->save();

            $errorSummary = is_array($result->error) ? $result->error : null;

            if ($result->status === AutomationRunStatus::Failed) {
                $this->log()->warning('Automation run finished with failures.', [
                    ...$baseLogContext,
                    'automation_run_id' => $run->id,
                    'status' => $result->status->value,
                    'step_count' => count($result->steps),
                    'has_error' => $errorSummary !== null,
                    'error_reason' => $errorSummary !== null ? Arr::get($errorSummary, 'reason') : null,
                ]);
            }
        } catch (\Throwable $exception) {
            $run->forceFill([
                'status' => AutomationRunStatus::Failed,
                'finished_at' => now(),
                'error_summary' => [
                    'reason' => 'workflow_execution_exception',
                    'message' => $exception->getMessage(),
                    'event_correlation_id' => $eventCorrelationId,
                    'run_correlation_id' => $runCorrelationId,
                ],
            ])->save();

            $this->log()->error('Automation run failed with exception.', [
                ...$baseLogContext,
                'automation_run_id' => $run->id,
                'exception' => $exception->getMessage(),
                'exception_class' => $exception::class,
            ]);
        }
    }

    private function resolveEventCorrelationId(): string
    {
        if (is_string($this->eventCorrelationId) && trim($this->eventCorrelationId) !== '') {
            return trim($this->eventCorrelationId);
        }

        return (string) Str::uuid();
    }

    private function log(): LoggerInterface
    {
        $configuredChannel = config('automation.log_channel', 'automation_pipeline');
        $logChannel = is_string($configuredChannel) && $configuredChannel !== ''
            ? $configuredChannel
            : 'automation_pipeline';

        return Log::channel($logChannel);
    }
}
