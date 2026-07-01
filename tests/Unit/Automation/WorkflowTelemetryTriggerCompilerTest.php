<?php

declare(strict_types=1);

use App\Domain\Automation\Data\WorkflowGraph;
use App\Domain\Automation\Enums\AutomationWorkflowStatus;
use App\Domain\Automation\Models\AutomationTelemetryTrigger;
use App\Domain\Automation\Models\AutomationWorkflow;
use App\Domain\Automation\Models\AutomationWorkflowVersion;
use App\Domain\Automation\Services\WorkflowTelemetryTriggerCompiler;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use App\Domain\Shared\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function createCompilerOrganization(): Organization
{
    return Organization::query()->create([
        'name' => 'Org '.Str::lower(Str::random(6)),
        'slug' => 'org-'.Str::lower(Str::random(10)),
    ]);
}

function createCompilerWorkflow(Organization $organization): AutomationWorkflow
{
    return AutomationWorkflow::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Workflow '.Str::lower(Str::random(6)),
        'slug' => 'workflow-'.Str::lower(Str::random(10)),
        'status' => AutomationWorkflowStatus::Draft->value,
        'created_by' => null,
        'updated_by' => null,
    ]);
}

function createCompilerFixture(Organization $organization): array
{
    $profileVersion = DeviceProfileVersion::factory()->active()->mqtt()->create();

    $device = Device::factory()->create([
        'organization_id' => $organization->id,
        'device_profile_version_id' => $profileVersion->id,
    ]);

    $channel = DeviceChannel::factory()->telemetry()->create([
        'device_profile_version_id' => $profileVersion->id,
        'key' => 'telemetry',
        'address' => 'telemetry',
    ]);

    $parameter = ProfileParameterDefinition::factory()->create([
        'device_channel_id' => $channel->id,
        'key' => 'voltage',
        'label' => 'Voltage',
        'json_path' => 'metrics.voltage',
        'type' => ParameterDataType::Decimal,
        'required' => true,
        'is_active' => true,
    ]);

    return [
        'device' => $device,
        'channel' => $channel,
        'parameter' => $parameter,
    ];
}

it('compiles configured telemetry trigger nodes into trigger rows', function (): void {
    $organization = createCompilerOrganization();
    $workflow = createCompilerWorkflow($organization);

    $version = AutomationWorkflowVersion::factory()->create([
        'automation_workflow_id' => $workflow->id,
    ]);

    $fixture = createCompilerFixture($organization);

    $graph = WorkflowGraph::fromArray([
        'version' => 1,
        'nodes' => [
            [
                'id' => 'trigger-1',
                'type' => 'telemetry-trigger',
                'data' => [
                    'config' => [
                        'mode' => 'event',
                        'source' => [
                            'device_id' => $fixture['device']->id,
                            'device_channel_id' => $fixture['channel']->id,
                            'parameter_key' => $fixture['parameter']->key,
                        ],
                    ],
                ],
            ],
        ],
        'edges' => [],
    ]);

    app(WorkflowTelemetryTriggerCompiler::class)->compile($workflow, $version, $graph);

    $trigger = AutomationTelemetryTrigger::query()->first();

    expect($trigger)->not->toBeNull()
        ->and($trigger?->organization_id)->toBe($organization->id)
        ->and($trigger?->workflow_version_id)->toBe($version->id)
        ->and($trigger?->device_id)->toBe($fixture['device']->id)
        ->and($trigger?->device_channel_id)->toBe($fixture['channel']->id)
        ->and($trigger?->channel_key)->toBe($fixture['channel']->key)
        ->and($trigger?->parameter_key)->toBe($fixture['parameter']->key);
});

it('skips telemetry nodes with incomplete configuration', function (): void {
    $organization = createCompilerOrganization();
    $workflow = createCompilerWorkflow($organization);

    $version = AutomationWorkflowVersion::factory()->create([
        'automation_workflow_id' => $workflow->id,
    ]);

    $graph = WorkflowGraph::fromArray([
        'version' => 1,
        'nodes' => [
            [
                'id' => 'trigger-1',
                'type' => 'telemetry-trigger',
                'data' => [
                    'config' => [
                        'mode' => 'event',
                        'source' => [
                            'device_id' => null,
                            'device_channel_id' => null,
                            'parameter_key' => null,
                        ],
                    ],
                ],
            ],
        ],
        'edges' => [],
    ]);

    app(WorkflowTelemetryTriggerCompiler::class)->compile($workflow, $version, $graph);

    expect(AutomationTelemetryTrigger::query()->count())->toBe(0);
});

it('replaces existing compiled triggers on recompile', function (): void {
    $organization = createCompilerOrganization();
    $workflow = createCompilerWorkflow($organization);

    $version = AutomationWorkflowVersion::factory()->create([
        'automation_workflow_id' => $workflow->id,
    ]);

    $fixture = createCompilerFixture($organization);

    AutomationTelemetryTrigger::factory()->create([
        'organization_id' => $organization->id,
        'workflow_version_id' => $version->id,
        'device_id' => null,
        'device_channel_id' => null,
        'channel_key' => null,
        'parameter_key' => null,
        'filter_expression' => null,
    ]);

    $graph = WorkflowGraph::fromArray([
        'version' => 1,
        'nodes' => [
            [
                'id' => 'trigger-1',
                'type' => 'telemetry-trigger',
                'data' => [
                    'config' => [
                        'mode' => 'event',
                        'source' => [
                            'device_id' => $fixture['device']->id,
                            'device_channel_id' => $fixture['channel']->id,
                            'parameter_key' => $fixture['parameter']->key,
                        ],
                    ],
                ],
            ],
        ],
        'edges' => [],
    ]);

    app(WorkflowTelemetryTriggerCompiler::class)->compile($workflow, $version, $graph);

    $triggers = AutomationTelemetryTrigger::query()->where('workflow_version_id', $version->id)->get();

    expect($triggers)->toHaveCount(1)
        ->and($triggers->first()?->device_id)->toBe($fixture['device']->id)
        ->and($triggers->first()?->device_channel_id)->toBe($fixture['channel']->id)
        ->and($triggers->first()?->parameter_key)->toBe($fixture['parameter']->key);
});
