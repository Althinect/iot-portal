<?php

declare(strict_types=1);

namespace App\Domain\Automation\Services;

use App\Domain\Alerts\Models\NotificationProfile;
use App\Domain\Alerts\Models\ThresholdPolicy;
use App\Domain\Alerts\Services\AlertIncidentManager;
use App\Domain\Automation\Data\WorkflowGraph;
use App\Domain\Automation\Enums\AutomationWorkflowStatus;
use App\Domain\Automation\Models\AutomationWorkflow;
use App\Domain\Automation\Models\AutomationWorkflowVersion;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ThresholdPolicyWorkflowProjector
{
    public const MANAGED_TYPE = 'threshold_policy';

    public function __construct(
        private readonly WorkflowGraphValidator $workflowGraphValidator,
        private readonly WorkflowNodeConfigValidator $workflowNodeConfigValidator,
        private readonly WorkflowTelemetryTriggerCompiler $workflowTelemetryTriggerCompiler,
        private readonly AlertIncidentManager $alertIncidentManager,
    ) {}

    public function sync(ThresholdPolicy $policy): ?AutomationWorkflow
    {
        $policy->loadMissing([
            'device',
            'deviceChannel',
            'notificationProfile',
            'managedWorkflow.activeVersion',
        ]);

        if ($policy->trashed()) {
            return $this->archiveManagedWorkflow($policy);
        }

        if ($this->shouldResolveOpenAlertOnSync($policy)) {
            $this->alertIncidentManager->normalizeThresholdAlert($policy);
        }

        if (! $this->policyCanProject($policy)) {
            return $this->pauseManagedWorkflow($policy);
        }

        $parameter = $policy->profileParameterDefinition();

        if ($parameter === null) {
            throw new RuntimeException("Threshold policy [{$policy->id}] does not reference an active profile parameter.");
        }

        $profile = $policy->notificationProfile;

        if (! $profile instanceof NotificationProfile) {
            return $this->pauseManagedWorkflow($policy);
        }

        return DB::transaction(function () use ($policy, $profile, $parameter): AutomationWorkflow {
            $workflow = $this->resolveManagedWorkflow($policy);

            $workflow->forceFill([
                'organization_id' => $policy->organization_id,
                'name' => $this->workflowName($policy),
                'slug' => $this->workflowSlug($policy),
                'status' => AutomationWorkflowStatus::Active,
                'is_managed' => true,
                'managed_type' => self::MANAGED_TYPE,
                'managed_metadata' => [
                    'threshold_policy_id' => $policy->id,
                    'notification_profile_id' => $profile->id,
                    'legacy_alert_rule_id' => $policy->legacy_alert_rule_id,
                ],
            ])->save();

            $graphPayload = $this->buildGraphPayload($policy, $profile, (int) $parameter->device_channel_id, $parameter->key);
            $graph = WorkflowGraph::fromArray($graphPayload);

            $this->workflowGraphValidator->validate($graph);
            $this->workflowNodeConfigValidator->validate($workflow, $graph);

            $version = $this->syncVersion($workflow, $graphPayload);

            $workflow->forceFill([
                'active_version_id' => $version->id,
                'status' => AutomationWorkflowStatus::Active,
            ])->save();

            $this->workflowTelemetryTriggerCompiler->compile($workflow, $version, $graph);

            if ((int) $policy->managed_workflow_id !== (int) $workflow->id) {
                $policy->forceFill([
                    'managed_workflow_id' => $workflow->id,
                ])->saveQuietly();
            }

            return $workflow->fresh(['activeVersion']) ?? $workflow;
        });
    }

    public function syncForNotificationProfile(NotificationProfile $profile): void
    {
        $profile->loadMissing('thresholdPolicies.deviceChannel', 'users');

        $profile->thresholdPolicies
            ->each(fn (ThresholdPolicy $policy): ?AutomationWorkflow => $this->sync($policy));
    }

    public function archiveManagedWorkflow(ThresholdPolicy $policy): ?AutomationWorkflow
    {
        $this->alertIncidentManager->normalizeThresholdAlert($policy);

        $workflow = $this->resolveExistingManagedWorkflow($policy);

        if (! $workflow instanceof AutomationWorkflow) {
            return null;
        }

        $workflow->forceFill([
            'status' => AutomationWorkflowStatus::Archived,
            'is_managed' => true,
            'managed_type' => self::MANAGED_TYPE,
            'managed_metadata' => [
                'threshold_policy_id' => $policy->id,
                'notification_profile_id' => $policy->notification_profile_id,
                'legacy_alert_rule_id' => $policy->legacy_alert_rule_id,
            ],
        ])->save();

        return $workflow;
    }

    private function pauseManagedWorkflow(ThresholdPolicy $policy): ?AutomationWorkflow
    {
        $this->alertIncidentManager->normalizeThresholdAlert($policy);

        $workflow = $this->resolveExistingManagedWorkflow($policy);

        if (! $workflow instanceof AutomationWorkflow) {
            return null;
        }

        $workflow->forceFill([
            'status' => AutomationWorkflowStatus::Paused,
            'is_managed' => true,
            'managed_type' => self::MANAGED_TYPE,
            'managed_metadata' => [
                'threshold_policy_id' => $policy->id,
                'notification_profile_id' => $policy->notification_profile_id,
                'legacy_alert_rule_id' => $policy->legacy_alert_rule_id,
            ],
        ])->save();

        return $workflow;
    }

    private function policyCanProject(ThresholdPolicy $policy): bool
    {
        if ($policy->is_active !== true || ! $policy->hasCondition()) {
            return false;
        }

        $profile = $policy->notificationProfile;

        if (! $profile instanceof NotificationProfile || $profile->enabled !== true) {
            return false;
        }

        return $profile->notifiableUsers()->isNotEmpty();
    }

    private function workflowName(ThresholdPolicy $policy): string
    {
        return 'Threshold Alert · '.$policy->name;
    }

    private function workflowSlug(ThresholdPolicy $policy): string
    {
        return 'threshold-policy-'.$policy->id;
    }

    private function resolveManagedWorkflow(ThresholdPolicy $policy): AutomationWorkflow
    {
        $workflow = $this->resolveExistingManagedWorkflow($policy);

        if ($workflow instanceof AutomationWorkflow) {
            return $workflow;
        }

        return new AutomationWorkflow;
    }

    private function resolveExistingManagedWorkflow(ThresholdPolicy $policy): ?AutomationWorkflow
    {
        if ($policy->managedWorkflow instanceof AutomationWorkflow) {
            return $policy->managedWorkflow;
        }

        if ($policy->managed_workflow_id !== null) {
            return AutomationWorkflow::query()->find($policy->managed_workflow_id);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildGraphPayload(
        ThresholdPolicy $policy,
        NotificationProfile $profile,
        int $deviceChannelId,
        string $parameterKey,
    ): array {
        $conditionLogic = $policy->resolvedConditionJsonLogic();
        $summary = sprintf(
            '%s, cooldown %d %s(s)',
            $profile->recipientCount().' user(s)',
            $policy->cooldown()['value'],
            $policy->cooldown()['unit'],
        );

        return [
            'version' => 1,
            'nodes' => [
                [
                    'id' => 'trigger-1',
                    'type' => 'telemetry-trigger',
                    'position' => ['x' => 120, 'y' => 120],
                    'data' => [
                        'label' => 'Telemetry Trigger',
                        'summary' => $policy->device->name ?? 'Device',
                        'config' => [
                            'mode' => 'event',
                            'source' => [
                                'device_id' => $policy->device_id,
                                'device_channel_id' => $deviceChannelId,
                                'parameter_key' => $parameterKey,
                            ],
                        ],
                    ],
                ],
                [
                    'id' => 'condition-1',
                    'type' => 'condition',
                    'position' => ['x' => 460, 'y' => 120],
                    'data' => [
                        'label' => 'Threshold Check',
                        'summary' => $policy->conditionLabel(),
                        'config' => [
                            'mode' => $policy->condition_mode === 'json_logic' ? 'json_logic' : 'guided',
                            'guided' => is_array($policy->getAttribute('guided_condition')) ? $policy->getAttribute('guided_condition') : null,
                            'json_logic' => $conditionLogic,
                        ],
                    ],
                ],
                [
                    'id' => 'alert-1',
                    'type' => 'alert',
                    'position' => ['x' => 800, 'y' => 120],
                    'data' => [
                        'label' => 'Alert',
                        'summary' => $summary,
                        'config' => [
                            'notification_profile_id' => $profile->id,
                            'cooldown' => $policy->cooldown(),
                            'metadata' => [
                                'notification_profile_id' => $profile->id,
                                'threshold_policy_id' => $policy->id,
                                'condition_label' => $policy->conditionLabel(),
                                'mask' => $profile->mask,
                                'campaign_name' => $profile->campaign_name,
                            ],
                        ],
                    ],
                ],
            ],
            'edges' => [
                ['id' => 'edge-1', 'source' => 'trigger-1', 'target' => 'condition-1'],
                ['id' => 'edge-2', 'source' => 'condition-1', 'target' => 'alert-1'],
            ],
            'viewport' => [
                'x' => 0,
                'y' => 0,
                'zoom' => 1,
            ],
        ];
    }

    private function shouldResolveOpenAlertOnSync(ThresholdPolicy $policy): bool
    {
        return $policy->wasChanged([
            'device_id',
            'device_channel_id',
            'parameter_key',
            'notification_profile_id',
            'minimum_value',
            'maximum_value',
            'condition_mode',
            'guided_condition',
            'condition_json_logic',
            'is_active',
        ]);
    }

    /**
     * @param  array<string, mixed>  $graphPayload
     */
    private function syncVersion(AutomationWorkflow $workflow, array $graphPayload): AutomationWorkflowVersion
    {
        $encodedGraph = json_encode($graphPayload, JSON_THROW_ON_ERROR);
        $graphChecksum = hash('sha256', $encodedGraph);

        $workflow->loadMissing('activeVersion');

        if (
            $workflow->activeVersion instanceof AutomationWorkflowVersion
            && $workflow->activeVersion->graph_checksum === $graphChecksum
        ) {
            $workflow->activeVersion->fill([
                'graph_json' => $graphPayload,
                'published_at' => now(),
            ])->save();

            return $workflow->activeVersion;
        }

        $latestVersion = $workflow->versions()->max('version');
        $nextVersion = (is_numeric($latestVersion) ? (int) $latestVersion : 0) + 1;

        return $workflow->versions()->create([
            'version' => max(1, $nextVersion),
            'graph_json' => $graphPayload,
            'graph_checksum' => $graphChecksum,
            'published_at' => now(),
        ]);
    }
}
