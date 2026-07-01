<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Automation\Data\WorkflowGraph;
use App\Domain\Automation\Enums\AutomationWorkflowStatus;
use App\Domain\Automation\Models\AutomationWorkflow;
use App\Domain\Automation\Models\AutomationWorkflowVersion;
use App\Domain\Automation\Services\WorkflowGraphValidator;
use App\Domain\Automation\Services\WorkflowNodeConfigValidator;
use App\Domain\Automation\Services\WorkflowTelemetryTriggerCompiler;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use App\Domain\Shared\Models\Organization;
use Illuminate\Database\Seeder;

class AutomationSeeder extends Seeder
{
    private const WORKFLOW_SLUG = 'energy-meter-current-a1-color-automation';

    private const QUERY_WORKFLOW_SLUG = 'energy-meter-consumption-window-rgb-alert';

    private const ENERGY_METER_EXTERNAL_ID = 'main-energy-meter-01';

    private const RGB_CONTROLLER_EXTERNAL_ID = 'rgb-led-01';

    public function __construct(
        protected WorkflowGraphValidator $workflowGraphValidator,
        protected WorkflowNodeConfigValidator $workflowNodeConfigValidator,
        protected WorkflowTelemetryTriggerCompiler $workflowTelemetryTriggerCompiler,
    ) {}

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $organization = Organization::query()->orderBy('id')->first();

        if (! $organization instanceof Organization) {
            $this->command?->warn('No organization found. Skipping automation seed.');

            return;
        }

        $sourceDevice = Device::query()
            ->where('organization_id', $organization->id)
            ->where('external_id', self::ENERGY_METER_EXTERNAL_ID)
            ->first();

        $targetDevice = Device::query()
            ->where('organization_id', $organization->id)
            ->where('external_id', self::RGB_CONTROLLER_EXTERNAL_ID)
            ->first();

        if (! $sourceDevice instanceof Device || ! $targetDevice instanceof Device) {
            $this->command?->warn('Required source/target devices were not found. Run device seeders first.');

            return;
        }

        $sourceChannel = DeviceChannel::query()
            ->where('device_profile_version_id', $sourceDevice->device_profile_version_id)
            ->where('key', 'telemetry')
            ->first();

        $currentSourceParameter = $sourceChannel instanceof DeviceChannel
            ? ProfileParameterDefinition::query()
                ->where('device_channel_id', $sourceChannel->id)
                ->where('key', 'A1')
                ->where('is_active', true)
                ->first()
            : null;

        $energySourceParameter = $sourceChannel instanceof DeviceChannel
            ? ProfileParameterDefinition::query()
                ->where('device_channel_id', $sourceChannel->id)
                ->where('key', 'total_energy_kwh')
                ->where('is_active', true)
                ->first()
            : null;

        $targetChannel = DeviceChannel::query()
            ->where('device_profile_version_id', $targetDevice->device_profile_version_id)
            ->where('key', 'lighting_control')
            ->first();

        if (
            ! $sourceChannel instanceof DeviceChannel
            || ! $currentSourceParameter instanceof ProfileParameterDefinition
            || ! $energySourceParameter instanceof ProfileParameterDefinition
            || ! $targetChannel instanceof DeviceChannel
        ) {
            $this->command?->warn('Required source/target channel configuration was not found. Run device profile seeders first.');

            return;
        }

        $this->upsertWorkflowWithGraph(
            organization: $organization,
            slug: self::WORKFLOW_SLUG,
            name: 'Energy Meter Current A1 to RGB Color',
            graph: $this->buildCurrentColorGraph(
                sourceDeviceId: $sourceDevice->id,
                sourceChannelId: $sourceChannel->id,
                sourceParameterKey: $currentSourceParameter->key,
                targetDeviceId: $targetDevice->id,
                targetChannelId: $targetChannel->id,
            ),
        );

        $this->upsertWorkflowWithGraph(
            organization: $organization,
            slug: self::QUERY_WORKFLOW_SLUG,
            name: 'Energy Meter 15 Minute Consumption to RGB Alert',
            graph: $this->buildConsumptionWindowQueryGraph(
                sourceDeviceId: $sourceDevice->id,
                sourceChannelId: $sourceChannel->id,
                sourceParameterKey: $energySourceParameter->key,
                targetDeviceId: $targetDevice->id,
                targetChannelId: $targetChannel->id,
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCurrentColorGraph(
        int $sourceDeviceId,
        int $sourceChannelId,
        string $sourceParameterKey,
        int $targetDeviceId,
        int $targetChannelId,
    ): array {
        return [
            'version' => 1,
            'nodes' => [
                [
                    'id' => 'trigger-current-a1',
                    'type' => 'telemetry-trigger',
                    'data' => [
                        'config' => [
                            'mode' => 'event',
                            'source' => [
                                'device_id' => $sourceDeviceId,
                                'device_channel_id' => $sourceChannelId,
                                'parameter_key' => $sourceParameterKey,
                            ],
                        ],
                    ],
                ],
                [
                    'id' => 'condition-yellow',
                    'type' => 'condition',
                    'data' => [
                        'config' => [
                            'mode' => 'json_logic',
                            'json_logic' => [
                                'and' => [
                                    [
                                        '>' => [
                                            ['var' => 'trigger.value'],
                                            20,
                                        ],
                                    ],
                                    [
                                        '<' => [
                                            ['var' => 'trigger.value'],
                                            40,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'id' => 'condition-purple',
                    'type' => 'condition',
                    'data' => [
                        'config' => [
                            'mode' => 'json_logic',
                            'json_logic' => [
                                'and' => [
                                    [
                                        '>=' => [
                                            ['var' => 'trigger.value'],
                                            40,
                                        ],
                                    ],
                                    [
                                        '<' => [
                                            ['var' => 'trigger.value'],
                                            60,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'id' => 'condition-blue',
                    'type' => 'condition',
                    'data' => [
                        'config' => [
                            'mode' => 'json_logic',
                            'json_logic' => [
                                '>' => [
                                    ['var' => 'trigger.value'],
                                    60,
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'id' => 'command-yellow',
                    'type' => 'command',
                    'data' => [
                        'config' => [
                            'target' => [
                                'device_id' => $targetDeviceId,
                                'device_channel_id' => $targetChannelId,
                            ],
                            'payload_mode' => 'profile_form',
                            'payload' => [
                                'power' => true,
                                'brightness' => 100,
                                'color_hex' => '#FFFF00',
                                'effect' => 'solid',
                            ],
                        ],
                    ],
                ],
                [
                    'id' => 'command-purple',
                    'type' => 'command',
                    'data' => [
                        'config' => [
                            'target' => [
                                'device_id' => $targetDeviceId,
                                'device_channel_id' => $targetChannelId,
                            ],
                            'payload_mode' => 'profile_form',
                            'payload' => [
                                'power' => true,
                                'brightness' => 100,
                                'color_hex' => '#800080',
                                'effect' => 'solid',
                            ],
                        ],
                    ],
                ],
                [
                    'id' => 'command-blue',
                    'type' => 'command',
                    'data' => [
                        'config' => [
                            'target' => [
                                'device_id' => $targetDeviceId,
                                'device_channel_id' => $targetChannelId,
                            ],
                            'payload_mode' => 'profile_form',
                            'payload' => [
                                'power' => true,
                                'brightness' => 100,
                                'color_hex' => '#0000FF',
                                'effect' => 'solid',
                            ],
                        ],
                    ],
                ],
            ],
            'edges' => [
                ['id' => 'edge-trigger-yellow', 'source' => 'trigger-current-a1', 'target' => 'condition-yellow'],
                ['id' => 'edge-trigger-purple', 'source' => 'trigger-current-a1', 'target' => 'condition-purple'],
                ['id' => 'edge-trigger-blue', 'source' => 'trigger-current-a1', 'target' => 'condition-blue'],
                ['id' => 'edge-yellow-command', 'source' => 'condition-yellow', 'target' => 'command-yellow'],
                ['id' => 'edge-purple-command', 'source' => 'condition-purple', 'target' => 'command-purple'],
                ['id' => 'edge-blue-command', 'source' => 'condition-blue', 'target' => 'command-blue'],
            ],
            'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildConsumptionWindowQueryGraph(
        int $sourceDeviceId,
        int $sourceChannelId,
        string $sourceParameterKey,
        int $targetDeviceId,
        int $targetChannelId,
    ): array {
        return [
            'version' => 1,
            'nodes' => [
                [
                    'id' => 'trigger-total-energy',
                    'type' => 'telemetry-trigger',
                    'data' => [
                        'config' => [
                            'mode' => 'event',
                            'source' => [
                                'device_id' => $sourceDeviceId,
                                'device_channel_id' => $sourceChannelId,
                                'parameter_key' => $sourceParameterKey,
                            ],
                        ],
                    ],
                ],
                [
                    'id' => 'query-energy-consumption-15m',
                    'type' => 'query',
                    'data' => [
                        'config' => [
                            'mode' => 'sql',
                            'window' => [
                                'size' => 15,
                                'unit' => 'minute',
                            ],
                            'sources' => [
                                [
                                    'alias' => 'source_1',
                                    'device_id' => $sourceDeviceId,
                                    'device_channel_id' => $sourceChannelId,
                                    'parameter_key' => $sourceParameterKey,
                                ],
                            ],
                            'sql' => 'SELECT COALESCE(MAX(source_1.value) - MIN(source_1.value), 0) AS value FROM source_1',
                        ],
                    ],
                ],
                [
                    'id' => 'condition-consumption-over-15',
                    'type' => 'condition',
                    'data' => [
                        'config' => [
                            'mode' => 'guided',
                            'guided' => [
                                'left' => 'query.value',
                                'operator' => '>',
                                'right' => 15,
                            ],
                            'json_logic' => [
                                '>' => [
                                    ['var' => 'query.value'],
                                    15,
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'id' => 'command-red-blink',
                    'type' => 'command',
                    'data' => [
                        'config' => [
                            'target' => [
                                'device_id' => $targetDeviceId,
                                'device_channel_id' => $targetChannelId,
                            ],
                            'payload_mode' => 'profile_form',
                            'payload' => [
                                'power' => true,
                                'brightness' => 100,
                                'color_hex' => '#FF0000',
                                'effect' => 'blink',
                            ],
                        ],
                    ],
                ],
            ],
            'edges' => [
                ['id' => 'edge-trigger-query', 'source' => 'trigger-total-energy', 'target' => 'query-energy-consumption-15m'],
                ['id' => 'edge-query-condition', 'source' => 'query-energy-consumption-15m', 'target' => 'condition-consumption-over-15'],
                ['id' => 'edge-condition-command', 'source' => 'condition-consumption-over-15', 'target' => 'command-red-blink'],
            ],
            'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1],
        ];
    }

    /**
     * @param  array<string, mixed>  $graph
     */
    private function upsertWorkflowWithGraph(
        Organization $organization,
        string $slug,
        string $name,
        array $graph,
    ): void {
        $workflow = AutomationWorkflow::query()->updateOrCreate(
            [
                'organization_id' => $organization->id,
                'slug' => $slug,
            ],
            [
                'name' => $name,
                'status' => AutomationWorkflowStatus::Active->value,
            ],
        );

        $workflowGraph = WorkflowGraph::fromArray($graph);
        $this->workflowGraphValidator->validate($workflowGraph);
        $this->workflowNodeConfigValidator->validate($workflow, $workflowGraph);

        $workflowVersion = AutomationWorkflowVersion::query()->updateOrCreate(
            [
                'automation_workflow_id' => $workflow->id,
                'version' => 1,
            ],
            [
                'graph_json' => $graph,
                'graph_checksum' => hash('sha256', json_encode($graph, JSON_THROW_ON_ERROR)),
                'published_at' => now(),
            ],
        );

        $workflow->update([
            'status' => AutomationWorkflowStatus::Active->value,
            'active_version_id' => $workflowVersion->id,
        ]);

        $this->workflowTelemetryTriggerCompiler->compile(
            workflow: $workflow,
            workflowVersion: $workflowVersion,
            graph: $workflowGraph,
        );
    }

    private function resolvePositiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $resolved = (int) $value;

        return $resolved > 0 ? $resolved : null;
    }
}
