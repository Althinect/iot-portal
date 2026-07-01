<?php

declare(strict_types=1);

namespace App\Domain\Automation\Services;

use App\Domain\Alerts\Models\Alert;
use App\Domain\Alerts\Models\NotificationProfile;
use App\Domain\Alerts\Models\ThresholdPolicy;
use App\Domain\Alerts\Services\AlertIncidentManager;
use App\Domain\Automation\Data\WorkflowExecutionResult;
use App\Domain\Automation\Data\WorkflowGraph;
use App\Domain\Automation\Enums\AutomationRunStatus;
use App\Domain\Automation\Models\AutomationRun;
use App\Domain\Automation\Models\AutomationRunStep;
use App\Domain\Automation\Models\AutomationWorkflow;
use App\Domain\Automation\Models\AutomationWorkflowVersion;
use App\Domain\DeviceControl\Enums\CommandStatus;
use App\Domain\DeviceControl\Services\DeviceCommandDispatcher;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Enums\MetricUnit;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use App\Domain\DeviceProfile\Services\JsonLogicEvaluator;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Cache\CacheManager;
use Illuminate\Log\LogManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use RuntimeException;

class WorkflowRunExecutor
{
    private const ALERT_DEFAULT_COOLDOWN_VALUE = 30;

    private const ALERT_DEFAULT_COOLDOWN_UNIT = 'minute';

    public function __construct(
        private readonly JsonLogicEvaluator $jsonLogicEvaluator,
        private readonly DeviceCommandDispatcher $deviceCommandDispatcher,
        private readonly WorkflowQueryExecutor $workflowQueryExecutor,
        private readonly WorkflowAlertDispatcher $workflowAlertDispatcher,
        private readonly AlertIncidentManager $alertIncidentManager,
        private readonly CacheManager $cacheManager,
        private readonly LogManager $logManager,
    ) {}

    public function executeTelemetryRun(
        AutomationRun $run,
        AutomationWorkflowVersion $workflowVersion,
        DeviceTelemetryLog $telemetryLog,
        string $runCorrelationId,
    ): WorkflowExecutionResult {
        $graph = WorkflowGraph::fromArray($this->resolveGraphPayload($workflowVersion));

        [$nodesById, $edgesBySource] = $this->buildGraphIndexes($graph);
        $triggerContexts = $this->resolveTriggerContexts($graph, $telemetryLog);
        $triggerPayload = $this->resolveAssociativeArray($run->getAttribute('trigger_payload'));
        $eventCorrelationId = Arr::get($triggerPayload, 'event_correlation_id');
        $telemetryLogId = $this->resolveKeyAsString($telemetryLog->getKey());

        $baseLogContext = [
            'event_correlation_id' => is_string($eventCorrelationId) && $eventCorrelationId !== '' ? $eventCorrelationId : null,
            'run_correlation_id' => $runCorrelationId,
            'automation_run_id' => $run->id,
            'workflow_id' => $run->workflow_id,
            'workflow_version_id' => $workflowVersion->id,
            'telemetry_log_id' => $telemetryLogId,
            'trigger_node_matches' => count($triggerContexts),
            'graph_node_count' => count($graph->nodes),
            'graph_edge_count' => count($graph->edges),
        ];

        if ($triggerContexts === []) {
            $this->log()->warning('Automation workflow execution found no matching trigger nodes.', $baseLogContext);

            return new WorkflowExecutionResult(
                status: AutomationRunStatus::Completed,
                steps: [],
                error: ['reason' => 'no_matching_trigger_nodes'],
            );
        }

        $hasFailures = false;
        $stepSummaries = [];
        $stepRecords = [];
        $stepSequence = 0;

        foreach ($triggerContexts as $triggerContext) {
            $triggerNode = $triggerContext['node'];
            $triggerNodeId = Arr::get($triggerNode, 'id');
            $triggerNodeType = Arr::get($triggerNode, 'type');

            if (! is_string($triggerNodeId) || ! is_string($triggerNodeType)) {
                continue;
            }

            $this->recordStep(
                run: $run,
                stepSummaries: $stepSummaries,
                stepRecords: $stepRecords,
                nodeId: $triggerNodeId,
                nodeType: $triggerNodeType,
                status: 'completed',
                input: [
                    'telemetry_log_id' => $telemetryLogId,
                    'device_id' => $telemetryLog->device_id,
                    'device_channel_id' => $telemetryLog->device_channel_id,
                ],
                output: $triggerContext['trigger'],
                error: null,
                startedAtMicrotime: microtime(true),
                runCorrelationId: $runCorrelationId,
                stepSequence: $stepSequence,
            );

            foreach ($edgesBySource[$triggerNodeId] ?? [] as $nextNodeId) {
                $this->executeFromNode(
                    run: $run,
                    nodeId: $nextNodeId,
                    nodesById: $nodesById,
                    edgesBySource: $edgesBySource,
                    executionContext: [
                        'trigger' => $triggerContext['trigger'],
                        'payload' => $triggerContext['payload'],
                        'query' => [],
                        'queries' => [],
                    ],
                    stepSummaries: $stepSummaries,
                    stepRecords: $stepRecords,
                    hasFailures: $hasFailures,
                    runCorrelationId: $runCorrelationId,
                    stepSequence: $stepSequence,
                );
            }
        }

        $finalStatus = $hasFailures ? AutomationRunStatus::Failed : AutomationRunStatus::Completed;
        $this->persistStepAudit($run, $stepRecords, $finalStatus);

        if ($hasFailures) {
            $this->log()->warning('Automation workflow execution finished with failures.', [
                ...$baseLogContext,
                'status' => $finalStatus->value,
                'step_count' => count($stepSummaries),
                'failed' => true,
            ]);
        }

        return new WorkflowExecutionResult(
            status: $finalStatus,
            steps: $stepSummaries,
            error: $hasFailures ? ['reason' => 'node_execution_failed'] : null,
        );
    }

    /**
     * @return array{
     *     0: array<string, array<string, mixed>>,
     *     1: array<string, array<int, string>>
     * }
     */
    private function buildGraphIndexes(WorkflowGraph $graph): array
    {
        $nodesById = [];

        foreach ($graph->nodes as $node) {
            $nodeId = Arr::get($node, 'id');

            if (! is_string($nodeId) || $nodeId === '') {
                continue;
            }

            $nodesById[$nodeId] = $node;
        }

        $edgesBySource = [];

        foreach ($graph->edges as $edge) {
            $source = Arr::get($edge, 'source');
            $target = Arr::get($edge, 'target');

            if (! is_string($source) || ! is_string($target)) {
                continue;
            }

            if (! isset($nodesById[$source], $nodesById[$target])) {
                continue;
            }

            $edgesBySource[$source][] = $target;
        }

        return [$nodesById, $edgesBySource];
    }

    /**
     * @return array<int, array{
     *     node: array<string, mixed>,
     *     trigger: array<string, mixed>,
     *     payload: array<string, mixed>
     * }>
     */
    private function resolveTriggerContexts(WorkflowGraph $graph, DeviceTelemetryLog $telemetryLog): array
    {
        $telemetryLog->loadMissing('device');

        $payload = $this->resolveAssociativeArray($telemetryLog->getAttribute('transformed_values'));
        $matchedTriggerNodes = [];
        $parameterKeysByChannel = [];

        foreach ($graph->nodes as $node) {
            if (Arr::get($node, 'type') !== 'telemetry-trigger') {
                continue;
            }

            $sourceDeviceId = $this->resolvePositiveInt(Arr::get($node, 'data.config.source.device_id'));
            $sourceChannelId = $this->resolvePositiveInt(
                Arr::get($node, 'data.config.source.device_channel_id')
                    ?? Arr::get($node, 'data.config.source.channel_id')
            );
            $parameterKey = $this->resolveNonEmptyString(Arr::get($node, 'data.config.source.parameter_key'));

            if ($sourceDeviceId === null || $sourceChannelId === null || $parameterKey === null) {
                continue;
            }

            if ((int) $telemetryLog->device_id !== $sourceDeviceId) {
                continue;
            }

            if ((int) $telemetryLog->device_channel_id !== $sourceChannelId) {
                continue;
            }

            $matchedTriggerNodes[] = [
                'node' => $node,
                'device_channel_id' => $sourceChannelId,
                'parameter_key' => $parameterKey,
            ];

            $parameterKeysByChannel[$sourceChannelId][] = $parameterKey;
        }

        if ($matchedTriggerNodes === [] || $parameterKeysByChannel === []) {
            return [];
        }

        $parameters = ProfileParameterDefinition::query()
            ->whereIn('device_channel_id', array_keys($parameterKeysByChannel))
            ->whereIn('key', array_values(array_unique(array_merge(...array_values($parameterKeysByChannel)))))
            ->get()
            ->keyBy(static fn (ProfileParameterDefinition $parameter): string => $parameter->device_channel_id.':'.$parameter->key);

        $channels = DeviceChannel::query()
            ->whereIn('id', array_keys($parameterKeysByChannel))
            ->get(['id', 'key'])
            ->keyBy('id');

        $contexts = [];

        foreach ($matchedTriggerNodes as $matchedTriggerNode) {
            $channelId = $matchedTriggerNode['device_channel_id'];
            $parameterKey = $matchedTriggerNode['parameter_key'];
            $parameter = $parameters->get($channelId.':'.$parameterKey);
            $channel = $channels->get($channelId);

            if (! $parameter instanceof ProfileParameterDefinition || ! $channel instanceof DeviceChannel) {
                continue;
            }

            if ((int) $parameter->device_channel_id !== (int) $channel->id) {
                continue;
            }

            $contexts[] = [
                'node' => $matchedTriggerNode['node'],
                'trigger' => [
                    'value' => $this->resolveTelemetryValue($payload, $parameter),
                    'parameter_key' => $parameter->key,
                    'parameter_label' => $parameter->label,
                    'parameter_unit' => $parameter->unit,
                    'device_id' => (int) $telemetryLog->device_id,
                    'device_name' => $telemetryLog->device?->name,
                    'device_channel_id' => (int) $telemetryLog->device_channel_id,
                    'channel_key' => $channel->key,
                    'recorded_at' => $telemetryLog->recorded_at->format(DATE_ATOM),
                ],
                'payload' => $payload,
            ];
        }

        return $contexts;
    }

    /**
     * @param  array<string, array<string, mixed>>  $nodesById
     * @param  array<string, array<int, string>>  $edgesBySource
     * @param  array<string, mixed>  $executionContext
     * @param  array<int, array<string, mixed>>  $stepSummaries
     * @param  array<int, array<string, mixed>>  $stepRecords
     */
    private function executeFromNode(
        AutomationRun $run,
        string $nodeId,
        array $nodesById,
        array $edgesBySource,
        array $executionContext,
        array &$stepSummaries,
        array &$stepRecords,
        bool &$hasFailures,
        string $runCorrelationId,
        int &$stepSequence,
    ): void {
        $node = $nodesById[$nodeId] ?? null;

        if (! is_array($node)) {
            return;
        }

        $nodeType = Arr::get($node, 'type');

        if (! is_string($nodeType) || $nodeType === '') {
            return;
        }

        $startedAt = microtime(true);

        if ($nodeType === 'condition') {
            $result = $this->runConditionNode(
                run: $run,
                node: $node,
                executionContext: $executionContext,
                runCorrelationId: $runCorrelationId,
                nodeId: $nodeId,
            );

            $this->recordStep(
                run: $run,
                stepSummaries: $stepSummaries,
                stepRecords: $stepRecords,
                nodeId: $nodeId,
                nodeType: $nodeType,
                status: $result['status'],
                input: ['context' => $executionContext],
                output: $result['output'],
                error: $result['error'],
                startedAtMicrotime: $startedAt,
                runCorrelationId: $runCorrelationId,
                stepSequence: $stepSequence,
            );

            if ($result['status'] === 'failed') {
                $hasFailures = true;

                return;
            }

            if ($result['continue'] !== true) {
                return;
            }

            foreach ($edgesBySource[$nodeId] ?? [] as $nextNodeId) {
                $this->executeFromNode(
                    run: $run,
                    nodeId: $nextNodeId,
                    nodesById: $nodesById,
                    edgesBySource: $edgesBySource,
                    executionContext: $executionContext,
                    stepSummaries: $stepSummaries,
                    stepRecords: $stepRecords,
                    hasFailures: $hasFailures,
                    runCorrelationId: $runCorrelationId,
                    stepSequence: $stepSequence,
                );
            }

            return;
        }

        if ($nodeType === 'query') {
            $result = $this->runQueryNode(
                run: $run,
                node: $node,
                executionContext: $executionContext,
                runCorrelationId: $runCorrelationId,
                nodeId: $nodeId,
            );

            $this->recordStep(
                run: $run,
                stepSummaries: $stepSummaries,
                stepRecords: $stepRecords,
                nodeId: $nodeId,
                nodeType: $nodeType,
                status: $result['status'],
                input: ['context' => $executionContext],
                output: $result['output'],
                error: $result['error'],
                startedAtMicrotime: $startedAt,
                runCorrelationId: $runCorrelationId,
                stepSequence: $stepSequence,
            );

            if ($result['status'] === 'failed') {
                $hasFailures = true;

                return;
            }

            foreach ($edgesBySource[$nodeId] ?? [] as $nextNodeId) {
                $this->executeFromNode(
                    run: $run,
                    nodeId: $nextNodeId,
                    nodesById: $nodesById,
                    edgesBySource: $edgesBySource,
                    executionContext: $result['execution_context'],
                    stepSummaries: $stepSummaries,
                    stepRecords: $stepRecords,
                    hasFailures: $hasFailures,
                    runCorrelationId: $runCorrelationId,
                    stepSequence: $stepSequence,
                );
            }

            return;
        }

        if ($nodeType === 'command') {
            $result = $this->runCommandNode(
                run: $run,
                node: $node,
                runCorrelationId: $runCorrelationId,
                nodeId: $nodeId,
            );

            $this->recordStep(
                run: $run,
                stepSummaries: $stepSummaries,
                stepRecords: $stepRecords,
                nodeId: $nodeId,
                nodeType: $nodeType,
                status: $result['status'],
                input: ['context' => $executionContext],
                output: $result['output'],
                error: $result['error'],
                startedAtMicrotime: $startedAt,
                runCorrelationId: $runCorrelationId,
                stepSequence: $stepSequence,
            );

            if ($result['status'] === 'failed') {
                $hasFailures = true;

                return;
            }

            foreach ($edgesBySource[$nodeId] ?? [] as $nextNodeId) {
                $this->executeFromNode(
                    run: $run,
                    nodeId: $nextNodeId,
                    nodesById: $nodesById,
                    edgesBySource: $edgesBySource,
                    executionContext: $executionContext,
                    stepSummaries: $stepSummaries,
                    stepRecords: $stepRecords,
                    hasFailures: $hasFailures,
                    runCorrelationId: $runCorrelationId,
                    stepSequence: $stepSequence,
                );
            }

            return;
        }

        if ($nodeType === 'alert') {
            $result = $this->runAlertNode(
                run: $run,
                node: $node,
                executionContext: $executionContext,
                runCorrelationId: $runCorrelationId,
                nodeId: $nodeId,
            );

            $this->recordStep(
                run: $run,
                stepSummaries: $stepSummaries,
                stepRecords: $stepRecords,
                nodeId: $nodeId,
                nodeType: $nodeType,
                status: $result['status'],
                input: ['context' => $executionContext],
                output: $result['output'],
                error: $result['error'],
                startedAtMicrotime: $startedAt,
                runCorrelationId: $runCorrelationId,
                stepSequence: $stepSequence,
            );

            if ($result['status'] === 'failed') {
                $hasFailures = true;

                return;
            }

            foreach ($edgesBySource[$nodeId] ?? [] as $nextNodeId) {
                $this->executeFromNode(
                    run: $run,
                    nodeId: $nextNodeId,
                    nodesById: $nodesById,
                    edgesBySource: $edgesBySource,
                    executionContext: $result['execution_context'],
                    stepSummaries: $stepSummaries,
                    stepRecords: $stepRecords,
                    hasFailures: $hasFailures,
                    runCorrelationId: $runCorrelationId,
                    stepSequence: $stepSequence,
                );
            }

            return;
        }

        $this->recordStep(
            run: $run,
            stepSummaries: $stepSummaries,
            stepRecords: $stepRecords,
            nodeId: $nodeId,
            nodeType: $nodeType,
            status: 'skipped',
            input: ['context' => $executionContext],
            output: ['reason' => 'node_type_not_implemented'],
            error: null,
            startedAtMicrotime: $startedAt,
            runCorrelationId: $runCorrelationId,
            stepSequence: $stepSequence,
        );

        foreach ($edgesBySource[$nodeId] ?? [] as $nextNodeId) {
            $this->executeFromNode(
                run: $run,
                nodeId: $nextNodeId,
                nodesById: $nodesById,
                edgesBySource: $edgesBySource,
                executionContext: $executionContext,
                stepSummaries: $stepSummaries,
                stepRecords: $stepRecords,
                hasFailures: $hasFailures,
                runCorrelationId: $runCorrelationId,
                stepSequence: $stepSequence,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $executionContext
     * @return array{
     *     status: 'completed'|'failed',
     *     continue: bool,
     *     output: array<string, mixed>,
     *     error: array<string, mixed>|null
     * }
     */
    private function runConditionNode(
        AutomationRun $run,
        array $node,
        array $executionContext,
        string $runCorrelationId,
        string $nodeId,
    ): array {
        $jsonLogic = Arr::get($node, 'data.config.json_logic');

        if (! is_array($jsonLogic) || $jsonLogic === []) {
            $this->log()->warning('Condition node failed due to missing json_logic configuration.', [
                'run_correlation_id' => $runCorrelationId,
                'node_id' => $nodeId,
            ]);

            return [
                'status' => 'failed',
                'continue' => false,
                'output' => [],
                'error' => ['reason' => 'condition_config_missing_json_logic'],
            ];
        }

        $evaluationData = $this->buildConditionEvaluationData($executionContext);
        $rawEvaluationResult = $this->jsonLogicEvaluator->evaluate($jsonLogic, $evaluationData);
        $passed = $this->resolveBoolean($rawEvaluationResult);
        $output = [
            'passed' => $passed,
            'evaluation_result' => $rawEvaluationResult,
        ];

        if ($passed !== true) {
            $output['normalization'] = $this->handleManagedThresholdNormalization(
                run: $run,
                executionContext: $executionContext,
                runCorrelationId: $runCorrelationId,
                nodeId: $nodeId,
            );
        }

        return [
            'status' => 'completed',
            'continue' => $passed,
            'output' => $output,
            'error' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $executionContext
     * @return array{
     *     status: 'completed'|'failed',
     *     execution_context: array<string, mixed>,
     *     output: array<string, mixed>,
     *     error: array<string, mixed>|null
     * }
     */
    private function runQueryNode(
        AutomationRun $run,
        array $node,
        array $executionContext,
        string $runCorrelationId,
        string $nodeId,
    ): array {
        $config = Arr::get($node, 'data.config');

        if (! is_array($config)) {
            $this->log()->warning('Query node failed due to missing configuration.', [
                'run_correlation_id' => $runCorrelationId,
                'node_id' => $nodeId,
            ]);

            return [
                'status' => 'failed',
                'execution_context' => $executionContext,
                'output' => [],
                'error' => ['reason' => 'query_config_missing'],
            ];
        }

        try {
            $normalizedConfig = $this->normalizeStringKeyArray($config);

            $result = $this->workflowQueryExecutor->execute(
                run: $run,
                config: $normalizedConfig,
                executionContext: $executionContext,
            );
        } catch (\Throwable $exception) {
            $this->log()->warning('Query node failed during execution.', [
                'run_correlation_id' => $runCorrelationId,
                'node_id' => $nodeId,
                'exception' => $exception->getMessage(),
            ]);

            return [
                'status' => 'failed',
                'execution_context' => $executionContext,
                'output' => [],
                'error' => [
                    'reason' => 'query_execution_failed',
                    'message' => $exception->getMessage(),
                ],
            ];
        }

        $nextExecutionContext = $this->applyQueryResultToExecutionContext($executionContext, $nodeId, $result);

        return [
            'status' => 'completed',
            'execution_context' => $nextExecutionContext,
            'output' => $result,
            'error' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $executionContext
     * @return array{
     *     status: 'completed'|'failed'|'skipped',
     *     execution_context: array<string, mixed>,
     *     output: array<string, mixed>,
     *     error: array<string, mixed>|null
     * }
     */
    private function runAlertNode(
        AutomationRun $run,
        array $node,
        array $executionContext,
        string $runCorrelationId,
        string $nodeId,
    ): array {
        $config = Arr::get($node, 'data.config');

        if (! is_array($config) || $config === []) {
            return [
                'status' => 'skipped',
                'execution_context' => $executionContext,
                'output' => ['reason' => 'alert_config_missing'],
                'error' => null,
            ];
        }

        $normalizedConfig = $this->normalizeStringKeyArray($config);
        $notificationProfileId = $this->resolvePositiveInt($normalizedConfig['notification_profile_id'] ?? null);

        if ($notificationProfileId !== null) {
            return $this->runNotificationProfileAlertNode(
                run: $run,
                normalizedConfig: $normalizedConfig,
                executionContext: $executionContext,
                runCorrelationId: $runCorrelationId,
                nodeId: $nodeId,
                notificationProfileId: $notificationProfileId,
            );
        }

        if (! $this->alertConfigHasRuntimeFields($normalizedConfig)) {
            return [
                'status' => 'skipped',
                'execution_context' => $executionContext,
                'output' => ['reason' => 'alert_config_legacy_placeholder'],
                'error' => null,
            ];
        }

        $channel = Arr::get($normalizedConfig, 'channel');
        $subjectTemplate = Arr::get($normalizedConfig, 'subject');
        $bodyTemplate = Arr::get($normalizedConfig, 'body');

        if (
            ! is_string($channel)
            || ! in_array($channel, $this->workflowAlertDispatcher->supportedChannels(), true)
            || ! is_string($subjectTemplate)
            || ! is_string($bodyTemplate)
        ) {
            $this->log()->warning('Alert node failed due to incomplete configuration.', [
                'run_correlation_id' => $runCorrelationId,
                'node_id' => $nodeId,
                'channel' => $channel,
            ]);

            return [
                'status' => 'failed',
                'execution_context' => $executionContext,
                'output' => [],
                'error' => ['reason' => 'alert_config_incomplete'],
            ];
        }

        try {
            $recipients = $this->resolveAlertRecipients($channel, Arr::get($normalizedConfig, 'recipients'));
        } catch (RuntimeException $exception) {
            return [
                'status' => 'failed',
                'execution_context' => $executionContext,
                'output' => [],
                'error' => [
                    'reason' => 'alert_recipients_invalid',
                    'message' => $exception->getMessage(),
                ],
            ];
        }

        if ($recipients === []) {
            return [
                'status' => 'failed',
                'execution_context' => $executionContext,
                'output' => [],
                'error' => ['reason' => 'alert_recipients_missing'],
            ];
        }

        $cooldown = $this->resolveAlertCooldown(Arr::get($normalizedConfig, 'cooldown'));
        $templateContext = $this->buildAlertTemplateContext($run, $executionContext, $nodeId, $cooldown);
        $alertMetadata = Arr::get($normalizedConfig, 'metadata');
        $resolvedAlertMetadata = is_array($alertMetadata) ? $this->normalizeStringKeyArray($alertMetadata) : [];
        $templateAlertContext = Arr::get($templateContext, 'alert', []);
        $dispatchContext = [
            ...$templateContext,
            'node' => [
                'id' => $nodeId,
                'config' => $normalizedConfig,
            ],
            'alert' => [
                ...(is_array($templateAlertContext) ? $templateAlertContext : []),
                'channel' => $channel,
                'metadata' => $resolvedAlertMetadata,
            ],
        ];
        $subject = trim($this->interpolateTemplate($subjectTemplate, $dispatchContext));
        $body = trim($this->interpolateTemplate($bodyTemplate, $dispatchContext));

        if ($subject === '' || $body === '') {
            return [
                'status' => 'failed',
                'execution_context' => $executionContext,
                'output' => [],
                'error' => ['reason' => 'alert_rendered_content_empty'],
            ];
        }

        $cooldownKey = $this->buildAlertCooldownCacheKey($run, $executionContext, $nodeId);
        $cooldownExpiresAt = $this->resolveAlertCooldownExpiration($cooldown);
        $cooldownAllowed = $this->cacheManager->store()->add($cooldownKey, now()->toIso8601String(), $cooldownExpiresAt);

        if ($cooldownAllowed !== true) {
            return [
                'status' => 'skipped',
                'execution_context' => $executionContext,
                'output' => [
                    'reason' => 'alert_cooldown_active',
                    'cooldown_key' => $cooldownKey,
                    'cooldown' => $cooldown,
                ],
                'error' => null,
            ];
        }

        try {
            $dispatchResult = $this->workflowAlertDispatcher->dispatch(
                channel: $channel,
                recipients: $recipients,
                subject: $subject,
                body: $body,
                context: $dispatchContext,
            );
        } catch (\Throwable $exception) {
            $this->cacheManager->store()->forget($cooldownKey);

            $this->log()->warning('Alert node failed to dispatch.', [
                'run_correlation_id' => $runCorrelationId,
                'node_id' => $nodeId,
                'exception' => $exception->getMessage(),
            ]);

            return [
                'status' => 'failed',
                'execution_context' => $executionContext,
                'output' => [],
                'error' => [
                    'reason' => 'alert_dispatch_failed',
                    'message' => $exception->getMessage(),
                ],
            ];
        }

        return [
            'status' => 'completed',
            'execution_context' => $executionContext,
            'output' => [
                'channel' => $channel,
                'subject' => $subject,
                'body' => $body,
                'recipients' => $recipients,
                'cooldown' => $cooldown,
                'cooldown_key' => $cooldownKey,
                'dispatch' => $dispatchResult,
            ],
            'error' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $normalizedConfig
     * @param  array<string, mixed>  $executionContext
     * @return array{
     *     status: 'completed'|'failed'|'skipped',
     *     execution_context: array<string, mixed>,
     *     output: array<string, mixed>,
     *     error: array<string, mixed>|null
     * }
     */
    private function runNotificationProfileAlertNode(
        AutomationRun $run,
        array $normalizedConfig,
        array $executionContext,
        string $runCorrelationId,
        string $nodeId,
        int $notificationProfileId,
    ): array {
        $managedThresholdPolicy = $this->resolveManagedThresholdPolicy($run);
        $triggerTelemetryLog = $managedThresholdPolicy instanceof ThresholdPolicy
            ? $this->resolveTriggerTelemetryLog($run)
            : null;
        $thresholdAlert = null;

        if ($managedThresholdPolicy instanceof ThresholdPolicy && $triggerTelemetryLog instanceof DeviceTelemetryLog) {
            $thresholdAlertResult = $this->alertIncidentManager->openThresholdAlert(
                policy: $managedThresholdPolicy,
                telemetryLog: $triggerTelemetryLog,
            );
            $thresholdAlert = $thresholdAlertResult['alert'];

            if ($thresholdAlertResult['was_created'] !== true) {
                return [
                    'status' => 'skipped',
                    'execution_context' => $executionContext,
                    'output' => [
                        'reason' => 'threshold_alert_open',
                        'alert_id' => $thresholdAlert->id,
                        'alerted_at' => $this->toIso8601String($thresholdAlert->alerted_at),
                    ],
                    'error' => null,
                ];
            }
        }

        $profile = NotificationProfile::query()
            ->with('users')
            ->whereKey($notificationProfileId)
            ->where('organization_id', (int) $run->organization_id)
            ->first();

        if (! $profile instanceof NotificationProfile) {
            return [
                'status' => 'failed',
                'execution_context' => $executionContext,
                'output' => $this->thresholdAlertOutput($thresholdAlert),
                'error' => ['reason' => 'alert_profile_invalid'],
            ];
        }

        if ($profile->enabled !== true) {
            return [
                'status' => 'failed',
                'execution_context' => $executionContext,
                'output' => $this->thresholdAlertOutput($thresholdAlert),
                'error' => ['reason' => 'alert_profile_disabled'],
            ];
        }

        $cooldown = $this->resolveAlertCooldown(Arr::get($normalizedConfig, 'cooldown'));
        $templateContext = $this->buildAlertTemplateContext($run, $executionContext, $nodeId, $cooldown);
        $alertMetadata = Arr::get($normalizedConfig, 'metadata');
        $resolvedAlertMetadata = is_array($alertMetadata) ? $this->normalizeStringKeyArray($alertMetadata) : [];
        $templateAlertContext = Arr::get($templateContext, 'alert', []);
        $dispatchContext = [
            ...$templateContext,
            'node' => [
                'id' => $nodeId,
                'config' => $normalizedConfig,
            ],
            'alert' => [
                ...(is_array($templateAlertContext) ? $templateAlertContext : []),
                'channel' => $profile->channel,
                'metadata' => [
                    ...$resolvedAlertMetadata,
                    'notification_profile_id' => $profile->id,
                    'mask' => $profile->mask,
                    'campaign_name' => $profile->campaign_name,
                ],
            ],
        ];
        $profileSubject = $profile->getAttribute('subject');
        $profileBody = $profile->getAttribute('body');
        $subjectTemplate = is_string($profileSubject) && trim($profileSubject) !== ''
            ? $profileSubject
            : 'Automation alert · '.$run->workflow?->name;
        $bodyTemplate = is_string($profileBody) ? $profileBody : '';
        $subject = trim($this->interpolateTemplate($subjectTemplate, $dispatchContext));
        $body = trim($this->interpolateTemplate($bodyTemplate, $dispatchContext));

        if ($body === '') {
            return [
                'status' => 'failed',
                'execution_context' => $executionContext,
                'output' => $this->thresholdAlertOutput($thresholdAlert),
                'error' => ['reason' => 'alert_rendered_content_empty'],
            ];
        }

        if ($managedThresholdPolicy instanceof ThresholdPolicy) {
            try {
                $dispatchResult = $this->workflowAlertDispatcher->dispatchProfile(
                    profile: $profile,
                    subject: $subject,
                    body: $body,
                    context: $dispatchContext,
                );
            } catch (\Throwable $exception) {
                $this->log()->warning('Managed threshold alert failed to dispatch.', [
                    'run_correlation_id' => $runCorrelationId,
                    'node_id' => $nodeId,
                    'threshold_policy_id' => $managedThresholdPolicy->id,
                    'exception' => $exception->getMessage(),
                ]);

                return [
                    'status' => 'failed',
                    'execution_context' => $executionContext,
                    'output' => $this->thresholdAlertOutput($thresholdAlert),
                    'error' => [
                        'reason' => 'alert_dispatch_failed',
                        'message' => $exception->getMessage(),
                    ],
                ];
            }

            if ($thresholdAlert instanceof Alert) {
                $thresholdAlert = $this->alertIncidentManager->markAlertNotificationSent($thresholdAlert);
            }

            return [
                'status' => 'completed',
                'execution_context' => $executionContext,
                'output' => [
                    'channel' => $profile->channel,
                    'notification_profile_id' => $profile->id,
                    'subject' => $subject,
                    'body' => $body,
                    'recipients' => $profile->normalizedRecipients(),
                    'cooldown' => $cooldown,
                    'dispatch' => $dispatchResult,
                    ...$this->thresholdAlertOutput($thresholdAlert),
                ],
                'error' => null,
            ];
        }

        $cooldownKey = $this->buildAlertCooldownCacheKey($run, $executionContext, $nodeId);
        $cooldownExpiresAt = $this->resolveAlertCooldownExpiration($cooldown);
        $cooldownAllowed = $this->cacheManager->store()->add($cooldownKey, now()->toIso8601String(), $cooldownExpiresAt);

        if ($cooldownAllowed !== true) {
            return [
                'status' => 'skipped',
                'execution_context' => $executionContext,
                'output' => [
                    'reason' => 'alert_cooldown_active',
                    'cooldown_key' => $cooldownKey,
                    'cooldown' => $cooldown,
                ],
                'error' => null,
            ];
        }

        try {
            $dispatchResult = $this->workflowAlertDispatcher->dispatchProfile(
                profile: $profile,
                subject: $subject,
                body: $body,
                context: $dispatchContext,
            );
        } catch (\Throwable $exception) {
            $this->cacheManager->store()->forget($cooldownKey);

            $this->log()->warning('Alert node failed to dispatch.', [
                'run_correlation_id' => $runCorrelationId,
                'node_id' => $nodeId,
                'exception' => $exception->getMessage(),
            ]);

            return [
                'status' => 'failed',
                'execution_context' => $executionContext,
                'output' => $this->thresholdAlertOutput($thresholdAlert),
                'error' => [
                    'reason' => 'alert_dispatch_failed',
                    'message' => $exception->getMessage(),
                ],
            ];
        }

        return [
            'status' => 'completed',
            'execution_context' => $executionContext,
            'output' => [
                'channel' => $profile->channel,
                'notification_profile_id' => $profile->id,
                'subject' => $subject,
                'body' => $body,
                'recipients' => $profile->normalizedRecipients(),
                'cooldown' => $cooldown,
                'cooldown_key' => $cooldownKey,
                'dispatch' => $dispatchResult,
                ...$this->thresholdAlertOutput($thresholdAlert),
            ],
            'error' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array{
     *     status: 'completed'|'failed',
     *     output: array<string, mixed>,
     *     error: array<string, mixed>|null
     * }
     */
    private function runCommandNode(
        AutomationRun $run,
        array $node,
        string $runCorrelationId,
        string $nodeId,
    ): array {
        $targetDeviceId = $this->resolvePositiveInt(Arr::get($node, 'data.config.target.device_id'));
        $targetChannelId = $this->resolvePositiveInt(
            Arr::get($node, 'data.config.target.device_channel_id')
                ?? Arr::get($node, 'data.config.target.channel_id')
        );
        $payload = Arr::get($node, 'data.config.payload');

        if ($targetDeviceId === null || $targetChannelId === null || ! is_array($payload)) {
            $this->log()->warning('Command node failed due to incomplete configuration.', [
                'run_correlation_id' => $runCorrelationId,
                'node_id' => $nodeId,
            ]);

            return [
                'status' => 'failed',
                'output' => [],
                'error' => ['reason' => 'command_config_incomplete'],
            ];
        }

        $resolvedPayload = $this->normalizeStringKeyArray($payload);

        $device = Device::query()
            ->where('organization_id', (int) $run->organization_id)
            ->find($targetDeviceId);

        $targetProfileVersionId = $device instanceof Device
            ? $this->resolvePositiveInt($device->getAttribute('device_profile_version_id'))
            : null;

        if (! $device instanceof Device || $targetProfileVersionId === null) {
            $this->log()->warning('Command node failed due to invalid target device.', [
                'run_correlation_id' => $runCorrelationId,
                'node_id' => $nodeId,
                'target_device_id' => $targetDeviceId,
            ]);

            return [
                'status' => 'failed',
                'output' => [],
                'error' => ['reason' => 'command_target_device_invalid'],
            ];
        }

        $channel = DeviceChannel::query()
            ->whereKey($targetChannelId)
            ->where('device_profile_version_id', $targetProfileVersionId)
            ->first();

        if (! $channel instanceof DeviceChannel || ! $channel->isPurposeCommand()) {
            $this->log()->warning('Command node failed due to invalid target channel.', [
                'run_correlation_id' => $runCorrelationId,
                'node_id' => $nodeId,
                'target_channel_id' => $targetChannelId,
                'target_device_id' => $targetDeviceId,
            ]);

            return [
                'status' => 'failed',
                'output' => [],
                'error' => ['reason' => 'command_target_channel_invalid'],
            ];
        }

        $commandLog = $this->deviceCommandDispatcher->dispatch(
            device: $device,
            channel: $channel,
            payload: $resolvedPayload,
            userId: null,
        );

        $commandStatus = $this->resolveCommandStatusValue($commandLog->status);

        if ($commandStatus === CommandStatus::Failed->value) {
            return [
                'status' => 'failed',
                'output' => [
                    'command_log_id' => $commandLog->id,
                    'command_status' => $commandStatus,
                ],
                'error' => [
                    'reason' => 'command_dispatch_failed',
                    'message' => $commandLog->error_message,
                ],
            ];
        }

        return [
            'status' => 'completed',
            'output' => [
                'command_log_id' => $commandLog->id,
                'command_status' => $commandStatus,
                'target_device_id' => $device->id,
                'target_channel_id' => $channel->id,
            ],
            'error' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $executionContext
     * @param  array<string, mixed>  $queryResult
     * @return array<string, mixed>
     */
    private function applyQueryResultToExecutionContext(array $executionContext, string $nodeId, array $queryResult): array
    {
        $queries = Arr::get($executionContext, 'queries');
        $resolvedQueries = is_array($queries) ? $queries : [];
        $resolvedQueries[$nodeId] = $queryResult;

        return [
            ...$executionContext,
            'query' => $queryResult,
            'queries' => $resolvedQueries,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function alertConfigHasRuntimeFields(array $config): bool
    {
        foreach (['channel', 'recipients', 'subject', 'body', 'cooldown'] as $runtimeKey) {
            if (array_key_exists($runtimeKey, $config)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function resolveAlertRecipients(string $channel, mixed $recipients): array
    {
        if (! is_array($recipients)) {
            return [];
        }

        $resolved = [];

        foreach ($recipients as $recipient) {
            if (! is_string($recipient)) {
                continue;
            }

            $resolvedRecipient = trim($recipient);

            if ($resolvedRecipient === '') {
                continue;
            }

            if ($channel === 'email') {
                $resolvedRecipient = strtolower($resolvedRecipient);

                if (filter_var($resolvedRecipient, FILTER_VALIDATE_EMAIL) === false) {
                    throw new RuntimeException("Invalid alert recipient [{$recipient}].");
                }

                $resolved[$resolvedRecipient] = $resolvedRecipient;

                continue;
            }

            if ($channel === 'sms') {
                if (! preg_match('/^94[0-9]{9}$/', $resolvedRecipient)) {
                    throw new RuntimeException("Invalid alert recipient [{$recipient}].");
                }

                $resolved[$resolvedRecipient] = $resolvedRecipient;

                continue;
            }

            throw new RuntimeException("Unsupported alert channel [{$channel}].");
        }

        return array_values($resolved);
    }

    /**
     * @return array{value: int, unit: string}
     */
    private function resolveAlertCooldown(mixed $cooldown): array
    {
        if (! is_array($cooldown)) {
            return [
                'value' => self::ALERT_DEFAULT_COOLDOWN_VALUE,
                'unit' => self::ALERT_DEFAULT_COOLDOWN_UNIT,
            ];
        }

        $value = $this->resolvePositiveInt($cooldown['value'] ?? null);
        $unit = $cooldown['unit'] ?? null;

        if ($value === null || ! is_string($unit) || ! in_array($unit, ['minute', 'hour', 'day'], true)) {
            return [
                'value' => self::ALERT_DEFAULT_COOLDOWN_VALUE,
                'unit' => self::ALERT_DEFAULT_COOLDOWN_UNIT,
            ];
        }

        return [
            'value' => $value,
            'unit' => $unit,
        ];
    }

    /**
     * @param  array<string, mixed>  $executionContext
     * @param  array{value: int, unit: string}  $cooldown
     * @return array<string, mixed>
     */
    private function buildAlertTemplateContext(
        AutomationRun $run,
        array $executionContext,
        string $nodeId,
        array $cooldown,
    ): array {
        $context = [
            'run' => [
                'id' => $run->id,
                'organization_id' => (int) $run->organization_id,
                'workflow_id' => (int) $run->workflow_id,
                'workflow_version_id' => (int) $run->workflow_version_id,
                'trigger_type' => $run->trigger_type,
            ],
            'trigger' => Arr::get($executionContext, 'trigger', []),
            'payload' => Arr::get($executionContext, 'payload', []),
            'query' => Arr::get($executionContext, 'query', []),
            'queries' => Arr::get($executionContext, 'queries', []),
            'alert' => [
                'node_id' => $nodeId,
                'cooldown' => $cooldown,
            ],
        ];

        $context['run_id'] = Arr::get($context, 'run.id');
        $context['workflow_id'] = Arr::get($context, 'run.workflow_id');
        $context['workflow_version_id'] = Arr::get($context, 'run.workflow_version_id');
        $context['trigger_value'] = Arr::get($context, 'trigger.value');
        $context['query_value'] = Arr::get($context, 'query.value');
        $context['window_start'] = Arr::get($context, 'query.window.start');
        $context['window_end'] = Arr::get($context, 'query.window.end');

        return $context;
    }

    /**
     * @param  array<string, mixed>  $executionContext
     * @return array<string, mixed>
     */
    private function buildConditionEvaluationData(array $executionContext): array
    {
        $evaluationData = [
            'trigger' => Arr::get($executionContext, 'trigger', []),
            'query' => Arr::get($executionContext, 'query', []),
            'queries' => Arr::get($executionContext, 'queries', []),
            'payload' => Arr::get($executionContext, 'payload', []),
        ];

        $payload = Arr::get($executionContext, 'payload');

        if (is_array($payload)) {
            $evaluationData = array_merge($payload, $evaluationData);
        }

        return $evaluationData;
    }

    /**
     * @param  array<string, mixed>  $executionContext
     * @return array<string, mixed>
     */
    private function handleManagedThresholdNormalization(
        AutomationRun $run,
        array $executionContext,
        string $runCorrelationId,
        string $nodeId,
    ): array {
        $policy = $this->resolveManagedThresholdPolicy($run);

        if (! $policy instanceof ThresholdPolicy) {
            return ['status' => 'not_applicable'];
        }

        $telemetryLog = $this->resolveTriggerTelemetryLog($run);
        $alert = $this->alertIncidentManager->normalizeThresholdAlert($policy, $telemetryLog);

        if (! $alert instanceof Alert) {
            return ['status' => 'no_open_alert'];
        }

        $profile = $policy->notificationProfile;

        if (! $profile instanceof NotificationProfile || $profile->enabled !== true || $profile->notifiableUsers()->isEmpty()) {
            return [
                'status' => 'normalized',
                'alert_id' => $alert->id,
                'normalized_at' => $this->toIso8601String($alert->normalized_at),
                'notification' => [
                    'status' => 'skipped',
                    'reason' => 'notification_profile_unavailable',
                ],
            ];
        }

        $notificationPayload = $this->buildThresholdNormalizedNotification(
            run: $run,
            policy: $policy,
            alert: $alert,
            executionContext: $executionContext,
        );

        try {
            $dispatchResult = $this->workflowAlertDispatcher->dispatchProfile(
                profile: $profile,
                subject: $notificationPayload['subject'],
                body: $notificationPayload['body'],
                context: $notificationPayload['context'],
            );
            $alert = $this->alertIncidentManager->markNormalizedNotificationSent($alert);
        } catch (\Throwable $exception) {
            $this->log()->warning('Managed threshold normalization notification failed to dispatch.', [
                'run_correlation_id' => $runCorrelationId,
                'node_id' => $nodeId,
                'threshold_policy_id' => $policy->id,
                'alert_id' => $alert->id,
                'exception' => $exception->getMessage(),
            ]);

            return [
                'status' => 'normalized',
                'alert_id' => $alert->id,
                'normalized_at' => $this->toIso8601String($alert->normalized_at),
                'notification' => [
                    'status' => 'failed',
                    'reason' => 'alert_dispatch_failed',
                    'message' => $exception->getMessage(),
                ],
            ];
        }

        return [
            'status' => 'normalized',
            'alert_id' => $alert->id,
            'normalized_at' => $this->toIso8601String($alert->normalized_at),
            'notification' => [
                'status' => 'sent',
                'subject' => $notificationPayload['subject'],
                'body' => $notificationPayload['body'],
                'dispatch' => $dispatchResult,
            ],
        ];
    }

    private function resolveManagedThresholdPolicy(AutomationRun $run): ?ThresholdPolicy
    {
        $run->loadMissing('workflow');
        $workflow = $run->workflow;

        if (! $workflow instanceof AutomationWorkflow || ! $workflow->isManagedBy(ThresholdPolicyWorkflowProjector::MANAGED_TYPE)) {
            return null;
        }

        $thresholdPolicyId = $this->resolvePositiveInt(data_get($workflow->managed_metadata, 'threshold_policy_id'));

        if ($thresholdPolicyId === null) {
            return null;
        }

        return ThresholdPolicy::query()
            ->with(['device', 'deviceChannel', 'notificationProfile.users'])
            ->whereKey($thresholdPolicyId)
            ->where('organization_id', (int) $run->organization_id)
            ->first();
    }

    private function resolveTriggerTelemetryLog(AutomationRun $run): ?DeviceTelemetryLog
    {
        $triggerPayload = $this->resolveAssociativeArray($run->getAttribute('trigger_payload'));
        $telemetryLogId = $this->resolveKeyAsString(Arr::get($triggerPayload, 'telemetry_log_id'));

        if ($telemetryLogId === null) {
            return null;
        }

        return DeviceTelemetryLog::query()
            ->with('device')
            ->find($telemetryLogId);
    }

    /**
     * @param  array<string, mixed>  $executionContext
     * @return array{subject: string, body: string, context: array<string, mixed>}
     */
    private function buildThresholdNormalizedNotification(
        AutomationRun $run,
        ThresholdPolicy $policy,
        Alert $alert,
        array $executionContext,
    ): array {
        $parameter = $policy->profileParameterDefinition();
        $device = $policy->device;
        $deviceName = $device instanceof Device
            ? $device->name
            : (is_string(Arr::get($executionContext, 'trigger.device_name')) ? Arr::get($executionContext, 'trigger.device_name') : $policy->name);
        $currentValue = Arr::get($executionContext, 'trigger.value');
        $currentValueDisplay = $this->formatAlertValue($currentValue, $parameter?->unit);
        $conditionLabel = $policy->conditionLabel($parameter?->unit);
        $normalizedAt = $this->toIso8601String($alert->normalized_at)
            ?? (is_string(Arr::get($executionContext, 'trigger.recorded_at')) ? Arr::get($executionContext, 'trigger.recorded_at') : now()->toIso8601String());
        $subject = sprintf('Threshold normalized · %s', $deviceName);
        $body = implode("\n", [
            sprintf('%s returned to the normal range.', $deviceName),
            'Rule: '.$conditionLabel,
            'Current value: '.$currentValueDisplay,
            'Normalized at: '.$normalizedAt,
        ]);
        $context = $this->buildAlertTemplateContext(
            run: $run,
            executionContext: $executionContext,
            nodeId: 'condition-normalized',
            cooldown: $policy->cooldown(),
        );
        $existingAlertContext = Arr::get($context, 'alert');
        $context['alert'] = [
            ...(is_array($existingAlertContext) ? $existingAlertContext : []),
            'id' => $alert->id,
            'status' => 'normalized',
            'alerted_at' => $this->toIso8601String($alert->alerted_at),
            'normalized_at' => $normalizedAt,
            'metadata' => [
                'threshold_policy_id' => $policy->id,
                'notification_profile_id' => $policy->notification_profile_id,
                'condition_label' => $conditionLabel,
            ],
        ];

        return [
            'subject' => $subject,
            'body' => $body,
            'context' => $context,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function thresholdAlertOutput(?Alert $alert): array
    {
        if (! $alert instanceof Alert) {
            return [];
        }

        return [
            'alert_id' => $alert->id,
            'alerted_at' => $this->toIso8601String($alert->alerted_at),
            'normalized_at' => $this->toIso8601String($alert->normalized_at),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function interpolateTemplate(string $template, array $context): string
    {
        $resolvedTemplate = preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/',
            static function (array $matches) use ($context): string {
                $path = $matches[1];
                $value = data_get($context, $path);

                if (is_scalar($value) || $value instanceof \Stringable) {
                    return (string) $value;
                }

                if (is_array($value)) {
                    $encoded = json_encode($value);

                    return is_string($encoded) ? $encoded : '';
                }

                return '';
            },
            $template,
        );

        return is_string($resolvedTemplate) ? $resolvedTemplate : $template;
    }

    /**
     * @param  array<string, mixed>  $executionContext
     */
    private function buildAlertCooldownCacheKey(AutomationRun $run, array $executionContext, string $nodeId): string
    {
        $deviceId = Arr::get($executionContext, 'trigger.device_id');
        $channelId = Arr::get($executionContext, 'trigger.device_channel_id');

        $resolvedDeviceId = is_int($deviceId) || is_string($deviceId) ? (string) $deviceId : 'none';
        $resolvedChannelId = is_int($channelId) || is_string($channelId) ? (string) $channelId : 'none';

        return implode(':', [
            'automation',
            'alert-cooldown',
            (string) $run->workflow_version_id,
            Str::slug($nodeId, '_'),
            $resolvedDeviceId,
            $resolvedChannelId,
        ]);
    }

    /**
     * @param  array{value: int, unit: string}  $cooldown
     */
    private function resolveAlertCooldownExpiration(array $cooldown): CarbonImmutable
    {
        $now = CarbonImmutable::now();

        return match ($cooldown['unit']) {
            'minute' => $now->addMinutes($cooldown['value']),
            'hour' => $now->addHours($cooldown['value']),
            'day' => $now->addDays($cooldown['value']),
            default => $now->addMinutes(self::ALERT_DEFAULT_COOLDOWN_VALUE),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveTelemetryValue(array $payload, ProfileParameterDefinition $parameter): mixed
    {
        $value = $parameter->extractValue($payload);

        if ($value !== null) {
            return $value;
        }

        return Arr::get($payload, $parameter->key);
    }

    private function formatAlertValue(mixed $value, ?string $unit = null): string
    {
        if (! is_numeric($value)) {
            return '—';
        }

        $formattedNumber = Number::format((float) $value, maxPrecision: 3);
        $formattedValue = is_string($formattedNumber)
            ? (str_contains($formattedNumber, '.')
                ? rtrim(rtrim($formattedNumber, '0'), '.')
                : $formattedNumber)
            : (string) $value;

        if (! is_string($unit) || trim($unit) === '') {
            return $formattedValue;
        }

        $resolvedUnit = match ($unit) {
            MetricUnit::Celsius->value => '°C',
            MetricUnit::Percent->value => '%',
            MetricUnit::Volts->value => 'V',
            MetricUnit::Amperes->value => 'A',
            MetricUnit::KilowattHours->value => 'kWh',
            MetricUnit::Watts->value => 'W',
            MetricUnit::Seconds->value => 's',
            MetricUnit::DecibelMilliwatts->value => 'dBm',
            MetricUnit::RevolutionsPerMinute->value => 'RPM',
            MetricUnit::Litres->value => 'L',
            MetricUnit::CubicMeters->value => 'm³',
            MetricUnit::LitersPerMinute->value => 'L/min',
            default => trim($unit),
        };

        return $formattedValue.$resolvedUnit;
    }

    private function resolveBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (float) $value !== 0.0;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            if ($normalized === '' || $normalized === '0' || $normalized === 'false' || $normalized === 'off') {
                return false;
            }

            return true;
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return $value !== null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $stepSummaries
     * @param  array<int, array<string, mixed>>  $stepRecords
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>|null  $error
     */
    private function recordStep(
        AutomationRun $run,
        array &$stepSummaries,
        array &$stepRecords,
        string $nodeId,
        string $nodeType,
        string $status,
        array $input,
        array $output,
        ?array $error,
        float $startedAtMicrotime,
        string $runCorrelationId,
        int &$stepSequence,
    ): void {
        $stepSequence++;
        $durationMs = max(1, (int) round((microtime(true) - $startedAtMicrotime) * 1000));
        $startedAt = now()->subMilliseconds($durationMs);
        $finishedAt = now();
        $stepCorrelationId = $this->buildStepCorrelationId($runCorrelationId, $stepSequence, $nodeId);

        $stepSummaries[] = [
            'node_id' => $nodeId,
            'node_type' => $nodeType,
            'status' => $status,
            'step_correlation_id' => $stepCorrelationId,
        ];

        $stepRecords[] = [
            'automation_run_id' => $run->id,
            'node_id' => $nodeId,
            'node_type' => $nodeType,
            'status' => $status,
            'input_snapshot' => $input,
            'output_snapshot' => $output,
            'error' => $error,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'duration_ms' => $durationMs,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $stepRecords
     */
    private function persistStepAudit(
        AutomationRun $run,
        array $stepRecords,
        AutomationRunStatus $finalStatus,
    ): void {
        if ($stepRecords === [] || ! $this->shouldPersistStepAudit($run, $finalStatus)) {
            return;
        }

        $captureSnapshots = $this->shouldCaptureStepSnapshots($finalStatus);
        $timestamp = now();
        $rows = array_map(function (array $stepRecord) use ($captureSnapshots, $timestamp): array {
            $startedAt = $stepRecord['started_at'] instanceof Carbon
                ? $stepRecord['started_at']
                : $timestamp;
            $finishedAt = $stepRecord['finished_at'] instanceof Carbon
                ? $stepRecord['finished_at']
                : $timestamp;

            if (! $captureSnapshots) {
                $stepRecord['input_snapshot'] = null;
                $stepRecord['output_snapshot'] = null;
            }

            return [
                'automation_run_id' => $stepRecord['automation_run_id'],
                'node_id' => $stepRecord['node_id'],
                'node_type' => $stepRecord['node_type'],
                'status' => $stepRecord['status'],
                'input_snapshot' => $stepRecord['input_snapshot'] !== null
                    ? json_encode($stepRecord['input_snapshot'], JSON_THROW_ON_ERROR)
                    : null,
                'output_snapshot' => $stepRecord['output_snapshot'] !== null
                    ? json_encode($stepRecord['output_snapshot'], JSON_THROW_ON_ERROR)
                    : null,
                'error' => $stepRecord['error'] !== null
                    ? json_encode($stepRecord['error'], JSON_THROW_ON_ERROR)
                    : null,
                'started_at' => $startedAt->toDateTimeString(),
                'finished_at' => $finishedAt->toDateTimeString(),
                'duration_ms' => $stepRecord['duration_ms'],
                'created_at' => $timestamp->toDateTimeString(),
                'updated_at' => $timestamp->toDateTimeString(),
            ];
        }, $stepRecords);

        AutomationRunStep::query()->insert($rows);
    }

    private function shouldPersistStepAudit(AutomationRun $run, AutomationRunStatus $finalStatus): bool
    {
        if ($finalStatus === AutomationRunStatus::Failed) {
            return true;
        }

        return match ($this->resolveStepLogMode()) {
            'all' => true,
            'sampled' => $this->shouldSampleSuccessfulStepLogs($run),
            default => false,
        };
    }

    private function shouldCaptureStepSnapshots(AutomationRunStatus $finalStatus): bool
    {
        if ($finalStatus === AutomationRunStatus::Failed) {
            return true;
        }

        return (bool) config('automation.capture_step_snapshots', false);
    }

    private function resolveStepLogMode(): string
    {
        $configuredMode = config('automation.step_log_mode', 'failures');

        if (! is_string($configuredMode)) {
            return 'failures';
        }

        return in_array($configuredMode, ['failures', 'sampled', 'all'], true)
            ? $configuredMode
            : 'failures';
    }

    private function shouldSampleSuccessfulStepLogs(AutomationRun $run): bool
    {
        $configuredSampleRate = config('automation.step_log_sample_rate', 0.0);

        if (! is_numeric($configuredSampleRate)) {
            return false;
        }

        $sampleRate = max(0.0, min(1.0, (float) $configuredSampleRate));

        if ($sampleRate === 0.0) {
            return false;
        }

        if ($sampleRate === 1.0) {
            return true;
        }

        $normalizedHash = (float) sprintf('%u', crc32((string) $run->id));

        return ($normalizedHash / 4_294_967_295) < $sampleRate;
    }

    private function buildStepCorrelationId(string $runCorrelationId, int $stepSequence, string $nodeId): string
    {
        $normalizedNodeId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $nodeId);
        $resolvedNodeId = is_string($normalizedNodeId) && $normalizedNodeId !== '' ? $normalizedNodeId : 'node';

        return "{$runCorrelationId}:{$stepSequence}:{$resolvedNodeId}";
    }

    private function log(): LoggerInterface
    {
        $configuredChannel = config('automation.log_channel', 'automation_pipeline');
        $logChannel = is_string($configuredChannel) && $configuredChannel !== ''
            ? $configuredChannel
            : 'automation_pipeline';

        return $this->logManager->channel($logChannel);
    }

    private function resolvePositiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value) || ! ctype_digit($value)) {
            return null;
        }

        $resolvedValue = (int) $value;

        return $resolvedValue > 0 ? $resolvedValue : null;
    }

    private function resolveNonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $resolved = trim($value);

        return $resolved === '' ? null : $resolved;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveGraphPayload(AutomationWorkflowVersion $workflowVersion): array
    {
        $payload = $workflowVersion->getAttribute('graph_json');

        return $this->resolveAssociativeArray($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveAssociativeArray(mixed $value): array
    {
        if (is_array($value)) {
            $resolved = [];

            foreach ($value as $key => $item) {
                if (is_string($key)) {
                    $resolved[$key] = $item;
                }
            }

            return $resolved;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decodedValue = json_decode($value, true);

        if (! is_array($decodedValue)) {
            return [];
        }

        $resolved = [];

        foreach ($decodedValue as $key => $item) {
            if (is_string($key)) {
                $resolved[$key] = $item;
            }
        }

        return $resolved;
    }

    /**
     * @param  array<mixed, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeStringKeyArray(array $payload): array
    {
        $resolved = [];

        foreach ($payload as $key => $item) {
            if (is_string($key)) {
                $resolved[$key] = $item;
            }
        }

        return $resolved;
    }

    private function resolveKeyAsString(mixed $value): ?string
    {
        if (is_int($value) || is_string($value)) {
            $resolved = (string) $value;

            return $resolved !== '' ? $resolved : null;
        }

        return null;
    }

    private function resolveCommandStatusValue(mixed $status): string
    {
        if ($status instanceof CommandStatus) {
            return $status->value;
        }

        return is_string($status) ? $status : CommandStatus::Failed->value;
    }

    private function toIso8601String(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        return null;
    }
}
