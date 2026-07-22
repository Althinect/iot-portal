<?php

declare(strict_types=1);

use App\Domain\Automation\Enums\AutomationWorkflowStatus;
use App\Domain\Automation\Models\AutomationTelemetryTrigger;
use App\Domain\Automation\Models\AutomationWorkflow;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use App\Filament\Admin\Resources\Automation\AutomationWorkflows\Pages\EditAutomationDag;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($this->admin);
});

it('normalizes builder identifiers before saving and compiling a workflow graph', function (): void {
    $organization = Organization::factory()->create();

    $sourceProfileVersion = DeviceProfileVersion::factory()->active()->mqtt()->create();
    $sourceChannel = DeviceChannel::factory()->telemetry()->create([
        'device_profile_version_id' => $sourceProfileVersion->id,
        'key' => 'telemetry',
    ]);
    $sourceParameter = ProfileParameterDefinition::factory()->create([
        'device_channel_id' => $sourceChannel->id,
        'key' => 'PhaseAVoltage',
        'json_path' => 'PhaseAVoltage',
        'type' => ParameterDataType::Decimal,
        'is_active' => true,
    ]);
    $sourceDevice = Device::factory()->create([
        'organization_id' => $organization->id,
        'device_profile_version_id' => $sourceProfileVersion->id,
    ]);

    $targetProfileVersion = DeviceProfileVersion::factory()->active()->mqtt()->create();
    $targetChannel = DeviceChannel::factory()->command()->create([
        'device_profile_version_id' => $targetProfileVersion->id,
        'key' => 'control',
    ]);
    ProfileParameterDefinition::factory()->subscribe()->create([
        'device_channel_id' => $targetChannel->id,
        'key' => 'alarm_on',
        'json_path' => 'alarm_on',
        'type' => ParameterDataType::Boolean,
        'required' => true,
        'default_value' => false,
        'is_active' => true,
    ]);
    $targetDevice = Device::factory()->create([
        'organization_id' => $organization->id,
        'device_profile_version_id' => $targetProfileVersion->id,
    ]);

    $workflow = AutomationWorkflow::factory()->create([
        'organization_id' => $organization->id,
        'status' => AutomationWorkflowStatus::Active,
    ]);

    $graph = [
        'version' => 1,
        'nodes' => [
            [
                'id' => 'trigger-1',
                'type' => 'telemetry-trigger',
                'data' => [
                    'config' => [
                        'mode' => 'event',
                        'source' => [
                            'device_id' => $sourceDevice->id,
                            'topic_id' => $sourceChannel->id,
                            'parameter_definition_id' => $sourceParameter->id,
                        ],
                    ],
                ],
            ],
            [
                'id' => 'condition-1',
                'type' => 'condition',
                'data' => [
                    'config' => [
                        'mode' => 'guided',
                        'guided' => [
                            'left' => 'trigger.value',
                            'operator' => '>',
                            'right' => 0,
                        ],
                        'json_logic' => [
                            '>' => [
                                ['var' => 'trigger.value'],
                                0,
                            ],
                        ],
                    ],
                ],
            ],
            [
                'id' => 'command-1',
                'type' => 'command',
                'data' => [
                    'config' => [
                        'target' => [
                            'device_id' => $targetDevice->id,
                            'topic_id' => $targetChannel->id,
                        ],
                        'payload_mode' => 'schema_form',
                        'payload' => ['alarm_on' => true],
                    ],
                ],
            ],
        ],
        'edges' => [
            ['id' => 'edge-1', 'source' => 'trigger-1', 'target' => 'condition-1'],
            ['id' => 'edge-2', 'source' => 'condition-1', 'target' => 'command-1'],
        ],
    ];

    livewire(EditAutomationDag::class, ['record' => $workflow->id])
        ->call('saveGraph', $graph);

    $workflow->refresh();
    $savedGraph = $workflow->activeVersion?->graph_json;

    expect($workflow->active_version_id)->not->toBeNull()
        ->and(data_get($savedGraph, 'nodes.0.data.config.source.device_channel_id'))->toBe($sourceChannel->id)
        ->and(data_get($savedGraph, 'nodes.0.data.config.source.parameter_key'))->toBe('PhaseAVoltage')
        ->and(data_get($savedGraph, 'nodes.2.data.config.target.device_channel_id'))->toBe($targetChannel->id)
        ->and(AutomationTelemetryTrigger::query()
            ->where('workflow_version_id', $workflow->active_version_id)
            ->where('device_id', $sourceDevice->id)
            ->where('device_channel_id', $sourceChannel->id)
            ->where('parameter_key', 'PhaseAVoltage')
            ->exists())->toBeTrue();
});
