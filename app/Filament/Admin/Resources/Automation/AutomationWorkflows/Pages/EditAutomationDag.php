<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Automation\AutomationWorkflows\Pages;

use App\Domain\Automation\Data\WorkflowGraph;
use App\Domain\Automation\Models\AutomationNotificationProfile;
use App\Domain\Automation\Models\AutomationWorkflow;
use App\Domain\Automation\Models\AutomationWorkflowVersion;
use App\Domain\Automation\Services\GuidedConditionService;
use App\Domain\Automation\Services\WorkflowBuilderGraphNormalizer;
use App\Domain\Automation\Services\WorkflowGraphValidator;
use App\Domain\Automation\Services\WorkflowNodeConfigValidator;
use App\Domain\Automation\Services\WorkflowTelemetryTriggerCompiler;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Enums\ChannelDirection;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use App\Filament\Admin\Resources\Automation\AutomationWorkflows\AutomationWorkflowResource;
use App\Filament\Admin\Resources\AutomationThresholdPolicies\AutomationThresholdPolicyResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

class EditAutomationDag extends Page
{
    use InteractsWithRecord;

    protected static string $resource = AutomationWorkflowResource::class;

    protected string $view = 'filament.admin.resources.automation.automation-workflows.pages.edit-automation-dag';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $initialGraph = [];

    public function mount(int|string $record): void
    {
        $resolvedRecord = $this->resolveRecord($record);
        if (! $resolvedRecord instanceof AutomationWorkflow) {
            throw new RuntimeException('Unable to resolve automation workflow record.');
        }

        $this->record = $resolvedRecord;

        if ($resolvedRecord->is_managed) {
            Notification::make()
                ->title('Managed workflows are read-only.')
                ->warning()
                ->send();

            $this->redirect($this->managedWorkflowRedirectUrl($resolvedRecord), navigate: true);

            return;
        }

        $version = $this->resolveEditableVersion($this->workflowRecord());
        $this->initialGraph = $this->resolveGraphPayload($version) ?? $this->defaultGraph();
    }

    public function getTitle(): string
    {
        $workflow = $this->workflowRecord();

        return "DAG Editor - {$workflow->name}";
    }

    public function getSubheading(): ?string
    {
        $version = $this->resolveEditableVersion($this->workflowRecord());

        return $version !== null
            ? "Editing active workflow version v{$version->version}."
            : 'No workflow version exists yet. Saving will create version v1.';
    }

    public function getMaxContentWidth(): string
    {
        return 'full';
    }

    /**
     * @return array<string, mixed>
     */
    public function getInitialGraphForBuilder(): array
    {
        return is_array($this->initialGraph) && $this->initialGraph !== []
            ? $this->initialGraph
            : $this->defaultGraph();
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{
     *     devices: array<int, array<string, mixed>>,
     *     topics: array<int, array<string, mixed>>,
     *     parameters: array<int, array<string, mixed>>
     * }
     */
    public function getTelemetryTriggerOptions(array $context = []): array
    {
        $workflow = $this->resolveOrganizationWorkflow();
        $organizationId = (int) $workflow->organization_id;

        $sourceDevice = $this->resolveOrganizationDevice($organizationId, $context['device_id'] ?? null);
        $sourceTopic = $this->resolveDeviceTopic($sourceDevice, $context['topic_id'] ?? null, ChannelDirection::Publish);

        return [
            'devices' => $this->buildOrganizationDeviceOptions($organizationId),
            'topics' => $sourceDevice instanceof Device
                ? $this->buildDeviceTopicOptions($sourceDevice, ChannelDirection::Publish)
                : [],
            'parameters' => $sourceTopic instanceof DeviceChannel
                ? $this->buildTopicParameterOptions($sourceTopic)
                : [],
        ];
    }

    /**
     * @return array{
     *     operators: array<int, array{value: string, label: string}>,
     *     left_operands: array<int, array{value: string, label: string}>,
     *     default_mode: string,
     *     default_json_logic: array<string, mixed>,
     *     default_guided: array<string, mixed>
     * }
     */
    public function getConditionTemplates(): array
    {
        $conditionService = app(GuidedConditionService::class);

        return [
            'left_operands' => [
                ['value' => 'trigger.value', 'label' => 'Trigger Value'],
                ['value' => 'query.value', 'label' => 'Query Value'],
            ],
            'operators' => $conditionService->operatorOptions(),
            'default_mode' => 'guided',
            'default_json_logic' => [
                '>' => [
                    ['var' => 'trigger.value'],
                    240,
                ],
            ],
            'default_guided' => [
                'left' => 'trigger.value',
                'operator' => '>',
                'right' => 240,
            ],
        ];
    }

    /**
     * @return array{
     *     profiles: array<int, array{id: int, label: string, channel: string}>
     * }
     */
    public function getAlertNodeOptions(): array
    {
        $workflow = $this->resolveOrganizationWorkflow();

        return [
            'profiles' => AutomationNotificationProfile::query()
                ->with('users')
                ->where('organization_id', (int) $workflow->organization_id)
                ->orderBy('name')
                ->get()
                ->map(fn (AutomationNotificationProfile $profile): array => [
                    'id' => (int) $profile->id,
                    'label' => sprintf(
                        '%s (%s · %d users)',
                        $profile->name,
                        strtoupper($profile->channel),
                        $profile->recipientCount(),
                    ),
                    'channel' => $profile->channel,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{
     *     devices: array<int, array<string, mixed>>,
     *     topics: array<int, array<string, mixed>>,
     *     parameters: array<int, array<string, mixed>>
     * }
     */
    public function getQueryNodeOptions(array $context = []): array
    {
        $workflow = $this->resolveOrganizationWorkflow();
        $organizationId = (int) $workflow->organization_id;

        $sourceDevice = $this->resolveOrganizationDevice($organizationId, $context['device_id'] ?? null);
        $sourceTopic = $this->resolveDeviceTopic($sourceDevice, $context['topic_id'] ?? null, ChannelDirection::Publish);

        return [
            'devices' => $this->buildOrganizationDeviceOptions($organizationId),
            'topics' => $sourceDevice instanceof Device
                ? $this->buildDeviceTopicOptions($sourceDevice, ChannelDirection::Publish)
                : [],
            'parameters' => $sourceTopic instanceof DeviceChannel
                ? $this->buildTopicParameterOptions($sourceTopic)
                : [],
        ];
    }

    /**
     * @return array{
     *     default_window: array{size: int, unit: string},
     *     window_units: array<int, array{value: string, label: string}>,
     *     runtime_tokens: array<int, array{label: string, token: string}>,
     *     sql_snippets: array<int, array{label: string, sql: string}>
     * }
     */
    public function getQueryNodeTemplates(): array
    {
        return [
            'default_window' => [
                'size' => 15,
                'unit' => 'minute',
            ],
            'window_units' => [
                ['value' => 'minute', 'label' => 'Minutes'],
                ['value' => 'hour', 'label' => 'Hours'],
                ['value' => 'day', 'label' => 'Days'],
            ],
            'runtime_tokens' => [
                ['label' => 'Trigger Value', 'token' => '{{ trigger.value }}'],
                ['label' => 'Trigger Device ID', 'token' => '{{ trigger.device_id }}'],
                ['label' => 'Query Value', 'token' => '{{ query.value }}'],
                ['label' => 'Window Start', 'token' => '{{ query.window.start }}'],
                ['label' => 'Window End', 'token' => '{{ query.window.end }}'],
                ['label' => 'Run ID', 'token' => '{{ run.id }}'],
                ['label' => 'Workflow ID', 'token' => '{{ run.workflow_id }}'],
                ['label' => 'Workflow Version ID', 'token' => '{{ run.workflow_version_id }}'],
            ],
            'sql_snippets' => [
                [
                    'label' => 'Average over source',
                    'sql' => 'SELECT AVG(source_1.value) AS value FROM source_1',
                ],
                [
                    'label' => 'Max over source',
                    'sql' => 'SELECT MAX(source_1.value) AS value FROM source_1',
                ],
                [
                    'label' => 'Difference between two sources',
                    'sql' => 'SELECT (AVG(source_1.value) - AVG(source_2.value)) AS value FROM source_1 CROSS JOIN source_2',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{
     *     devices: array<int, array<string, mixed>>,
     *     topics: array<int, array<string, mixed>>,
     *     parameters: array<int, array<string, mixed>>
     * }
     */
    public function getCommandNodeOptions(array $context = []): array
    {
        $workflow = $this->resolveOrganizationWorkflow();
        $organizationId = (int) $workflow->organization_id;

        $targetDevice = $this->resolveOrganizationDevice($organizationId, $context['device_id'] ?? null);
        $targetTopic = $this->resolveDeviceTopic($targetDevice, $context['topic_id'] ?? null, ChannelDirection::Subscribe);

        return [
            'devices' => $this->buildOrganizationDeviceOptions($organizationId),
            'topics' => $targetDevice instanceof Device
                ? $this->buildDeviceTopicOptions($targetDevice, ChannelDirection::Subscribe)
                : [],
            'parameters' => $targetTopic instanceof DeviceChannel
                ? $this->buildTopicParameterOptions($targetTopic)
                : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{
     *     value: mixed,
     *     recorded_at: string|null,
     *     parameter: array{key: string, label: string, unit: string|null}
     * }|null
     */
    public function previewLatestTelemetryValue(array $context = []): ?array
    {
        $workflow = $this->resolveOrganizationWorkflow();
        $organizationId = (int) $workflow->organization_id;

        $sourceDevice = $this->resolveOrganizationDevice($organizationId, $context['device_id'] ?? null);
        if (! $sourceDevice instanceof Device) {
            return null;
        }

        $sourceTopic = $this->resolveDeviceTopic($sourceDevice, $context['topic_id'] ?? null, ChannelDirection::Publish);
        if (! $sourceTopic instanceof DeviceChannel) {
            return null;
        }

        $parameterDefinitionId = $this->resolvePositiveInt(
            $context['parameter_definition_id'] ?? $context['parameter_key'] ?? null,
        );
        if ($parameterDefinitionId === null) {
            return null;
        }

        $parameter = ProfileParameterDefinition::query()
            ->whereKey($parameterDefinitionId)
            ->where('device_channel_id', $sourceTopic->id)
            ->where('is_active', true)
            ->first();

        if (! $parameter instanceof ProfileParameterDefinition) {
            return null;
        }

        $latestTelemetry = DeviceTelemetryLog::query()
            ->where('device_id', $sourceDevice->id)
            ->where('device_channel_id', $sourceTopic->id)
            ->latest('recorded_at')
            ->first();

        if (! $latestTelemetry instanceof DeviceTelemetryLog) {
            return null;
        }

        $transformedValues = $latestTelemetry->getAttribute('transformed_values');
        if (! is_array($transformedValues)) {
            return null;
        }

        $recordedAt = $latestTelemetry->getAttribute('recorded_at');
        $recordedAtIso = $recordedAt instanceof \DateTimeInterface
            ? $recordedAt->format(DATE_ATOM)
            : null;

        return [
            'value' => $parameter->extractValue($this->normalizeStringKeyArray($transformedValues)),
            'recorded_at' => $recordedAtIso,
            'parameter' => [
                'key' => $parameter->key,
                'label' => $parameter->label,
                'unit' => $parameter->unit,
            ],
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('workflowDetails')
                ->label('Workflow Details')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->url(fn (): string => $this->workflowResourceUrl('edit', ['record' => $this->getRecord()])),
        ];
    }

    protected function managedWorkflowRedirectUrl(AutomationWorkflow $workflow): string
    {
        $thresholdPolicyId = data_get($workflow->managed_metadata, 'threshold_policy_id');

        return is_numeric($thresholdPolicyId)
            ? AutomationThresholdPolicyResource::getUrl('edit', ['record' => (int) $thresholdPolicyId])
            : $this->workflowResourceUrl('view', ['record' => $workflow]);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    protected function workflowResourceUrl(string $page, array $parameters = []): string
    {
        $resource = static::getResource();

        return $resource::getUrl($page, $parameters);
    }

    /**
     * @param  array<string, mixed>  $graph
     */
    public function saveGraph(array $graph): void
    {
        $builderGraph = app(WorkflowBuilderGraphNormalizer::class)->normalize($graph);
        $workflowGraph = WorkflowGraph::fromArray($builderGraph);

        try {
            app(WorkflowGraphValidator::class)->validate($workflowGraph);
        } catch (RuntimeException $exception) {
            Notification::make()
                ->title('Graph validation failed.')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $normalizedGraph = $workflowGraph->toArray();

        try {
            $encodedGraph = json_encode($normalizedGraph, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            Notification::make()
                ->title('Unable to encode graph JSON.')
                ->danger()
                ->send();

            return;
        }

        try {
            DB::transaction(function () use ($workflowGraph, $normalizedGraph, $encodedGraph): void {
                /** @var AutomationWorkflow $workflow */
                $workflow = AutomationWorkflow::query()
                    ->with('activeVersion')
                    ->lockForUpdate()
                    ->findOrFail($this->resolveRecordKey($this->workflowRecord()));

                app(WorkflowNodeConfigValidator::class)->validate($workflow, $workflowGraph);

                $version = $this->resolveEditableVersion($workflow);

                if ($version === null) {
                    $latestVersion = $this->resolveNonNegativeInt($workflow->versions()->max('version')) ?? 0;
                    $nextVersion = max(1, $latestVersion + 1);

                    $version = $workflow->versions()->create([
                        'version' => $nextVersion,
                        'graph_json' => $normalizedGraph,
                        'graph_checksum' => hash('sha256', $encodedGraph),
                    ]);
                } else {
                    $version->fill([
                        'graph_json' => $normalizedGraph,
                        'graph_checksum' => hash('sha256', $encodedGraph),
                    ])->save();
                }

                app(WorkflowTelemetryTriggerCompiler::class)->compile($workflow, $version, $workflowGraph);

                $activeVersionId = $this->resolvePositiveInt($workflow->getAttribute('active_version_id'));
                $resolvedVersionId = $this->resolvePositiveInt($version->getKey());

                if ($resolvedVersionId === null) {
                    throw new RuntimeException('Unable to resolve workflow version id.');
                }

                $workflow->forceFill([
                    'active_version_id' => $activeVersionId ?? $resolvedVersionId,
                    'updated_by' => auth()->id(),
                ])->save();
            });
        } catch (RuntimeException $exception) {
            Notification::make()
                ->title('Node configuration failed.')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->record = $this->resolveRecord($this->resolveRecordKey($this->workflowRecord()));
        $this->initialGraph = $normalizedGraph;

        Notification::make()
            ->title('DAG saved')
            ->success()
            ->send();
    }

    private function resolveEditableVersion(AutomationWorkflow $workflow): ?AutomationWorkflowVersion
    {
        $workflow->loadMissing('activeVersion');

        if ($workflow->activeVersion !== null) {
            return $workflow->activeVersion;
        }

        return $workflow->versions()
            ->latest('version')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultGraph(): array
    {
        return [
            'version' => 1,
            'nodes' => [
                [
                    'id' => 'trigger-1',
                    'type' => 'telemetry-trigger',
                    'data' => [],
                    'position' => ['x' => 120, 'y' => 120],
                ],
                [
                    'id' => 'condition-1',
                    'type' => 'condition',
                    'data' => [],
                    'position' => ['x' => 460, 'y' => 120],
                ],
                [
                    'id' => 'command-1',
                    'type' => 'command',
                    'data' => [],
                    'position' => ['x' => 800, 'y' => 120],
                ],
            ],
            'edges' => [
                [
                    'id' => 'edge-1',
                    'source' => 'trigger-1',
                    'target' => 'condition-1',
                ],
                [
                    'id' => 'edge-2',
                    'source' => 'condition-1',
                    'target' => 'command-1',
                ],
            ],
            'viewport' => [
                'x' => 0,
                'y' => 0,
                'zoom' => 1,
            ],
        ];
    }

    private function resolveOrganizationWorkflow(): AutomationWorkflow
    {
        /** @var AutomationWorkflow $workflow */
        $workflow = AutomationWorkflow::query()
            ->findOrFail($this->resolveRecordKey($this->workflowRecord()));

        return $workflow;
    }

    private function resolveOrganizationDevice(int $organizationId, mixed $deviceId): ?Device
    {
        $resolvedDeviceId = $this->resolvePositiveInt($deviceId);
        if ($resolvedDeviceId === null) {
            return null;
        }

        return Device::query()
            ->where('organization_id', $organizationId)
            ->find($resolvedDeviceId);
    }

    private function resolveDeviceTopic(?Device $device, mixed $topicId, ChannelDirection $direction): ?DeviceChannel
    {
        if (! $device instanceof Device) {
            return null;
        }

        $profileVersionId = $this->resolvePositiveInt($device->getAttribute('device_profile_version_id'));
        if ($profileVersionId === null) {
            return null;
        }

        $resolvedTopicId = $this->resolvePositiveInt($topicId);
        if ($resolvedTopicId === null) {
            return null;
        }

        return DeviceChannel::query()
            ->whereKey($resolvedTopicId)
            ->where('device_profile_version_id', $profileVersionId)
            ->where('direction', $direction->value)
            ->first();
    }

    /**
     * @return array<int, array{id: int, label: string, name: string, external_id: string|null}>
     */
    private function buildOrganizationDeviceOptions(int $organizationId): array
    {
        return Device::query()
            ->where('organization_id', $organizationId)
            ->orderBy('name')
            ->get(['id', 'name', 'external_id'])
            ->map(static function (Device $device): array {
                $externalId = is_string($device->external_id) && $device->external_id !== '' ? $device->external_id : null;

                return [
                    'id' => $device->id,
                    'label' => $externalId === null
                        ? $device->name
                        : "{$device->name} ({$externalId})",
                    'name' => $device->name,
                    'external_id' => $externalId,
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array{id: int, label: string, key: string, address: string, purpose: string|null}>
     */
    private function buildDeviceTopicOptions(Device $device, ChannelDirection $direction): array
    {
        $profileVersionId = $this->resolvePositiveInt($device->getAttribute('device_profile_version_id'));
        if ($profileVersionId === null) {
            return [];
        }

        return DeviceChannel::query()
            ->where('device_profile_version_id', $profileVersionId)
            ->where('direction', $direction->value)
            ->orderBy('sequence')
            ->get(['id', 'label', 'key', 'address', 'purpose'])
            ->map(static function (DeviceChannel $topic): array {
                $purpose = $topic->getAttribute('purpose');
                $purposeValue = null;

                if ($purpose instanceof \BackedEnum) {
                    $purposeValue = (string) $purpose->value;
                } elseif (is_string($purpose)) {
                    $purposeValue = $purpose;
                }

                return [
                    'id' => $topic->id,
                    'label' => "{$topic->label} ({$topic->address})",
                    'key' => $topic->key,
                    'address' => $topic->address,
                    'purpose' => $purposeValue,
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildTopicParameterOptions(DeviceChannel $topic): array
    {
        return ProfileParameterDefinition::query()
            ->where('device_channel_id', $topic->id)
            ->where('is_active', true)
            ->orderBy('sequence')
            ->get()
            ->map(static function (ProfileParameterDefinition $parameter): array {
                $parameterType = $parameter->getAttribute('type');
                $type = null;

                if ($parameterType instanceof \BackedEnum) {
                    $type = (string) $parameterType->value;
                } elseif (is_string($parameterType)) {
                    $type = $parameterType;
                }

                return [
                    'id' => $parameter->id,
                    'key' => $parameter->key,
                    'label' => $parameter->label,
                    'json_path' => $parameter->json_path,
                    'type' => $type,
                    'unit' => $parameter->unit,
                    'required' => (bool) $parameter->required,
                    'widget' => $parameter->resolvedWidgetType()->value,
                    'default' => $parameter->resolvedDefaultValue(),
                    'options' => $parameter->resolvedSelectOptions(),
                    'range' => $parameter->resolvedNumericRange(),
                ];
            })
            ->all();
    }

    private function resolvePositiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value) || ! ctype_digit($value)) {
            return null;
        }

        $resolved = (int) $value;

        return $resolved > 0 ? $resolved : null;
    }

    private function resolveNonNegativeInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (! is_string($value) || ! ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveGraphPayload(?AutomationWorkflowVersion $version): ?array
    {
        if (! $version instanceof AutomationWorkflowVersion) {
            return null;
        }

        $graphPayload = $version->getAttribute('graph_json');
        if (! is_array($graphPayload) || $graphPayload === []) {
            return null;
        }

        return $this->normalizeStringKeyArray($graphPayload);
    }

    private function workflowRecord(): AutomationWorkflow
    {
        $record = $this->getRecord();

        if (! $record instanceof AutomationWorkflow) {
            throw new RuntimeException('Unable to resolve automation workflow record.');
        }

        return $record;
    }

    private function resolveRecordKey(AutomationWorkflow $workflow): int|string
    {
        $key = $workflow->getKey();

        if (is_int($key) || is_string($key)) {
            return $key;
        }

        throw new RuntimeException('Unable to resolve automation workflow key.');
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
