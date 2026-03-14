<?php

declare(strict_types=1);

namespace App\Domain\Automation\Services;

use App\Domain\Automation\Contracts\TriggerMatcher;
use App\Domain\Automation\Enums\AutomationWorkflowStatus;
use App\Domain\Automation\Models\AutomationTelemetryTrigger;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceSchema\Services\JsonLogicEvaluator;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use Illuminate\Cache\CacheManager;
use Illuminate\Log\LogManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

class DatabaseTriggerMatcher implements TriggerMatcher
{
    public function __construct(
        private readonly JsonLogicEvaluator $jsonLogicEvaluator,
        private readonly AutomationTriggerCacheInvalidator $automationTriggerCacheInvalidator,
        private readonly CacheManager $cacheManager,
        private readonly LogManager $logManager,
    ) {}

    public function hasCandidateTelemetryTriggers(DeviceTelemetryLog $telemetryLog): bool
    {
        $device = $telemetryLog->device;

        if (! $device instanceof Device) {
            return false;
        }

        return $this->candidateTriggerRows($telemetryLog, $device)->isNotEmpty();
    }

    public function matchTelemetryTriggers(DeviceTelemetryLog $telemetryLog): Collection
    {
        $telemetryLogId = $this->resolveKeyAsString($telemetryLog->getKey());
        $device = $telemetryLog->device;
        $baseLogContext = [
            'telemetry_log_id' => $telemetryLogId,
            'device_id' => $telemetryLog->device_id,
            'schema_version_topic_id' => $telemetryLog->schema_version_topic_id,
        ];

        if ($device === null) {
            $this->log()->warning('Automation trigger matcher skipped telemetry because device was missing.', $baseLogContext);

            return collect();
        }

        $candidates = $this->candidateTriggerRows($telemetryLog, $device);

        $matchedWorkflowVersionIds = $candidates
            ->filter(function (array $triggerRow) use ($telemetryLog): bool {
                $expression = Arr::get($triggerRow, 'filter_expression');
                if (! is_array($expression) || $expression === []) {
                    return true;
                }

                $payload = $telemetryLog->getAttribute('transformed_values');

                if (! is_array($payload)) {
                    return false;
                }

                $evaluationResult = $this->jsonLogicEvaluator->evaluate(
                    $expression,
                    $this->normalizeStringKeyArray($payload),
                );

                return (bool) $evaluationResult;
            })
            ->pluck('workflow_version_id')
            ->map(static function (mixed $id): ?int {
                if (! is_numeric($id)) {
                    return null;
                }

                return (int) $id;
            })
            ->filter(static fn (?int $id): bool => $id !== null)
            ->unique()
            ->values();

        return $matchedWorkflowVersionIds;
    }

    /**
     * @return Collection<int, array{
     *     workflow_version_id: int,
     *     device_id: int|null,
     *     device_type_id: int|null,
     *     schema_version_topic_id: int|null,
     *     filter_expression: array<string, mixed>|null
     * }>
     */
    private function candidateTriggerRows(DeviceTelemetryLog $telemetryLog, Device $device): Collection
    {
        return collect($this->resolveOrganizationTriggersFromCache((int) $device->organization_id))
            ->filter(function (array $triggerRow) use ($device, $telemetryLog): bool {
                $triggerDeviceId = $this->resolveNullableInt(Arr::get($triggerRow, 'device_id'));
                $triggerDeviceTypeId = $this->resolveNullableInt(Arr::get($triggerRow, 'device_type_id'));
                $triggerTopicId = $this->resolveNullableInt(Arr::get($triggerRow, 'schema_version_topic_id'));

                $deviceMatches = $triggerDeviceId === null || $triggerDeviceId === (int) $device->id;
                $deviceTypeMatches = $triggerDeviceTypeId === null || $triggerDeviceTypeId === (int) $device->device_type_id;
                $topicMatches = $triggerTopicId === null || $triggerTopicId === (int) $telemetryLog->schema_version_topic_id;

                return $deviceMatches && $deviceTypeMatches && $topicMatches;
            })
            ->values();
    }

    /**
     * @return array<int, array{
     *     workflow_version_id: int,
     *     device_id: int|null,
     *     device_type_id: int|null,
     *     schema_version_topic_id: int|null,
     *     filter_expression: array<string, mixed>|null
     * }>
     */
    private function resolveOrganizationTriggersFromCache(int $organizationId): array
    {
        $cacheKey = $this->buildOrganizationCacheKey($organizationId);

        /** @var array<int, array{
         *     workflow_version_id: int,
         *     device_id: int|null,
         *     device_type_id: int|null,
         *     schema_version_topic_id: int|null,
         *     filter_expression: array<string, mixed>|null
         * }> $resolved */
        $resolved = $this->cacheManager->store()->rememberForever($cacheKey, function () use ($organizationId): array {
            return AutomationTelemetryTrigger::query()
                ->join('automation_workflow_versions', 'automation_workflow_versions.id', '=', 'automation_telemetry_triggers.workflow_version_id')
                ->join('automation_workflows', 'automation_workflows.id', '=', 'automation_workflow_versions.automation_workflow_id')
                ->where('automation_telemetry_triggers.organization_id', $organizationId)
                ->where('automation_workflows.status', AutomationWorkflowStatus::Active->value)
                ->whereColumn('automation_workflows.active_version_id', 'automation_workflow_versions.id')
                ->get([
                    'automation_telemetry_triggers.workflow_version_id',
                    'automation_telemetry_triggers.device_id',
                    'automation_telemetry_triggers.device_type_id',
                    'automation_telemetry_triggers.schema_version_topic_id',
                    'automation_telemetry_triggers.filter_expression',
                ])
                ->map(function (AutomationTelemetryTrigger $trigger): array {
                    $filterExpression = $trigger->getAttribute('filter_expression');

                    return [
                        'workflow_version_id' => (int) $trigger->workflow_version_id,
                        'device_id' => is_numeric($trigger->device_id) ? (int) $trigger->device_id : null,
                        'device_type_id' => is_numeric($trigger->device_type_id) ? (int) $trigger->device_type_id : null,
                        'schema_version_topic_id' => is_numeric($trigger->schema_version_topic_id) ? (int) $trigger->schema_version_topic_id : null,
                        'filter_expression' => is_array($filterExpression) ? $this->normalizeStringKeyArray($filterExpression) : null,
                    ];
                })
                ->values()
                ->all();
        });

        return $resolved;
    }

    private function buildOrganizationCacheKey(int $organizationId): string
    {
        $cacheVersion = $this->automationTriggerCacheInvalidator->currentVersion();

        return "automation:trigger-matcher:v{$cacheVersion}:org:{$organizationId}";
    }

    private function log(): LoggerInterface
    {
        $configuredChannel = config('automation.log_channel', 'automation_pipeline');
        $logChannel = is_string($configuredChannel) && $configuredChannel !== ''
            ? $configuredChannel
            : 'automation_pipeline';

        return $this->logManager->channel($logChannel);
    }

    private function resolveKeyAsString(mixed $value): ?string
    {
        if (is_int($value) || is_string($value)) {
            $resolved = (string) $value;

            return $resolved !== '' ? $resolved : null;
        }

        return null;
    }

    private function resolveNullableInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param  array<mixed, mixed>  $values
     * @return array<string, mixed>
     */
    private function normalizeStringKeyArray(array $values): array
    {
        $normalized = [];

        foreach ($values as $key => $value) {
            $normalized[(string) $key] = $value;
        }

        return $normalized;
    }
}
