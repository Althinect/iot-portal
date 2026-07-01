<?php

declare(strict_types=1);

use App\Domain\Automation\Data\WorkflowGraph;
use App\Domain\Automation\Enums\AutomationWorkflowStatus;
use App\Domain\Automation\Models\AutomationWorkflow;
use App\Domain\Automation\Services\WorkflowNodeConfigValidator;
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

function createOrganizationRecord(): Organization
{
    return Organization::query()->create([
        'name' => 'Org '.Str::lower(Str::random(6)),
        'slug' => 'org-'.Str::lower(Str::random(10)),
    ]);
}

function createWorkflowRecord(Organization $organization): AutomationWorkflow
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

function createWorkflowDeviceFixture(Organization $organization): array
{
    $profileVersion = DeviceProfileVersion::factory()->active()->mqtt()->create();

    $device = Device::factory()->create([
        'organization_id' => $organization->id,
        'device_profile_version_id' => $profileVersion->id,
    ]);

    $publishChannel = DeviceChannel::factory()->telemetry()->create([
        'device_profile_version_id' => $profileVersion->id,
        'key' => 'telemetry',
        'address' => 'telemetry',
    ]);

    $commandChannel = DeviceChannel::factory()->command()->create([
        'device_profile_version_id' => $profileVersion->id,
        'key' => 'commands',
        'address' => 'commands',
    ]);

    $voltageParameter = ProfileParameterDefinition::factory()->create([
        'device_channel_id' => $publishChannel->id,
        'key' => 'voltage',
        'label' => 'Voltage',
        'json_path' => 'metrics.voltage',
        'type' => ParameterDataType::Decimal,
        'required' => true,
        'is_active' => true,
        'mutation_expression' => null,
    ]);

    $brightnessParameter = ProfileParameterDefinition::factory()->subscribe()->create([
        'device_channel_id' => $commandChannel->id,
        'key' => 'brightness',
        'label' => 'Brightness',
        'json_path' => 'brightness',
        'type' => ParameterDataType::Integer,
        'required' => true,
        'validation_rules' => [
            'min' => 0,
            'max' => 100,
        ],
        'default_value' => 0,
        'is_active' => true,
    ]);

    return [
        'device' => $device,
        'publishChannel' => $publishChannel,
        'commandChannel' => $commandChannel,
        'voltageParameter' => $voltageParameter,
        'brightnessParameter' => $brightnessParameter,
    ];
}

it('fails when telemetry trigger config is missing required fields', function (): void {
    $workflow = createWorkflowRecord(createOrganizationRecord());

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
                            'device_id' => 123,
                            'device_channel_id' => 456,
                        ],
                    ],
                ],
            ],
        ],
        'edges' => [],
    ]);

    expect(fn () => app(WorkflowNodeConfigValidator::class)->validate($workflow, $graph))
        ->toThrow(RuntimeException::class, 'missing source device, channel, or parameter');
});

it('fails when condition node has invalid json logic', function (): void {
    $workflow = createWorkflowRecord(createOrganizationRecord());

    $graph = WorkflowGraph::fromArray([
        'version' => 1,
        'nodes' => [
            [
                'id' => 'condition-1',
                'type' => 'condition',
                'data' => [
                    'config' => [
                        'mode' => 'json_logic',
                        'json_logic' => [],
                    ],
                ],
            ],
        ],
        'edges' => [],
    ]);

    expect(fn () => app(WorkflowNodeConfigValidator::class)->validate($workflow, $graph))
        ->toThrow(RuntimeException::class, 'must define valid JSON logic');
});

it('fails when command payload values do not match parameter requirements', function (): void {
    $organization = createOrganizationRecord();
    $workflow = createWorkflowRecord($organization);

    $fixture = createWorkflowDeviceFixture($organization);

    $graph = WorkflowGraph::fromArray([
        'version' => 1,
        'nodes' => [
            [
                'id' => 'command-1',
                'type' => 'command',
                'data' => [
                    'config' => [
                        'target' => [
                            'device_id' => $fixture['device']->id,
                            'device_channel_id' => $fixture['commandChannel']->id,
                        ],
                        'payload_mode' => 'schema_form',
                        'payload' => [
                            'brightness' => 200,
                        ],
                    ],
                ],
            ],
        ],
        'edges' => [],
    ]);

    expect(fn () => app(WorkflowNodeConfigValidator::class)->validate($workflow, $graph))
        ->toThrow(RuntimeException::class, 'invalid payload values: brightness');
});

it('validates query node configuration when source and sql are valid', function (): void {
    $organization = createOrganizationRecord();
    $workflow = createWorkflowRecord($organization);
    $fixture = createWorkflowDeviceFixture($organization);

    $graph = WorkflowGraph::fromArray([
        'version' => 1,
        'nodes' => [
            [
                'id' => 'query-1',
                'type' => 'query',
                'data' => [
                    'config' => [
                        'mode' => 'sql',
                        'window' => [
                            'size' => 30,
                            'unit' => 'minute',
                        ],
                        'sources' => [
                            [
                                'alias' => 'source_1',
                                'device_id' => $fixture['device']->id,
                                'device_channel_id' => $fixture['publishChannel']->id,
                                'parameter_key' => $fixture['voltageParameter']->key,
                            ],
                        ],
                        'sql' => 'SELECT AVG(source_1.value) AS value FROM source_1',
                    ],
                ],
            ],
        ],
        'edges' => [],
    ]);

    expect(fn () => app(WorkflowNodeConfigValidator::class)->validate($workflow, $graph))
        ->not->toThrow(RuntimeException::class);
});

it('fails when query node sql is not select-only', function (): void {
    $organization = createOrganizationRecord();
    $workflow = createWorkflowRecord($organization);
    $fixture = createWorkflowDeviceFixture($organization);

    $graph = WorkflowGraph::fromArray([
        'version' => 1,
        'nodes' => [
            [
                'id' => 'query-1',
                'type' => 'query',
                'data' => [
                    'config' => [
                        'mode' => 'sql',
                        'window' => [
                            'size' => 30,
                            'unit' => 'minute',
                        ],
                        'sources' => [
                            [
                                'alias' => 'source_1',
                                'device_id' => $fixture['device']->id,
                                'device_channel_id' => $fixture['publishChannel']->id,
                                'parameter_key' => $fixture['voltageParameter']->key,
                            ],
                        ],
                        'sql' => 'SELECT update AS value FROM source_1',
                    ],
                ],
            ],
        ],
        'edges' => [],
    ]);

    expect(fn () => app(WorkflowNodeConfigValidator::class)->validate($workflow, $graph))
        ->toThrow(RuntimeException::class, 'contains forbidden keyword [update]');
});

it('fails when query node source is outside workflow organization scope', function (): void {
    $organization = createOrganizationRecord();
    $workflow = createWorkflowRecord($organization);
    $otherFixture = createWorkflowDeviceFixture(createOrganizationRecord());

    $graph = WorkflowGraph::fromArray([
        'version' => 1,
        'nodes' => [
            [
                'id' => 'query-1',
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
                                'device_id' => $otherFixture['device']->id,
                                'device_channel_id' => $otherFixture['publishChannel']->id,
                                'parameter_key' => $otherFixture['voltageParameter']->key,
                            ],
                        ],
                        'sql' => 'SELECT AVG(source_1.value) AS value FROM source_1',
                    ],
                ],
            ],
        ],
        'edges' => [],
    ]);

    expect(fn () => app(WorkflowNodeConfigValidator::class)->validate($workflow, $graph))
        ->toThrow(RuntimeException::class, 'references invalid device');
});

it('allows guided condition to use query value as left operand', function (): void {
    $workflow = createWorkflowRecord(createOrganizationRecord());

    $graph = WorkflowGraph::fromArray([
        'version' => 1,
        'nodes' => [
            [
                'id' => 'condition-1',
                'type' => 'condition',
                'data' => [
                    'config' => [
                        'mode' => 'guided',
                        'guided' => [
                            'left' => 'query.value',
                            'operator' => '>',
                            'right' => 1,
                        ],
                        'json_logic' => [
                            '>' => [
                                ['var' => 'query.value'],
                                1,
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'edges' => [],
    ]);

    expect(fn () => app(WorkflowNodeConfigValidator::class)->validate($workflow, $graph))
        ->not->toThrow(RuntimeException::class);
});

it('validates alert node configuration for email channel', function (): void {
    $workflow = createWorkflowRecord(createOrganizationRecord());

    $graph = WorkflowGraph::fromArray([
        'version' => 1,
        'nodes' => [
            [
                'id' => 'alert-1',
                'type' => 'alert',
                'data' => [
                    'config' => [
                        'channel' => 'email',
                        'recipients' => ['alerts@example.com', 'ops@example.com'],
                        'subject' => 'Threshold exceeded',
                        'body' => 'Query value is {{ query.value }}',
                        'cooldown' => [
                            'value' => 30,
                            'unit' => 'minute',
                        ],
                    ],
                ],
            ],
        ],
        'edges' => [],
    ]);

    expect(fn () => app(WorkflowNodeConfigValidator::class)->validate($workflow, $graph))
        ->not->toThrow(RuntimeException::class);
});

it('validates alert node configuration for sms channel', function (): void {
    $workflow = createWorkflowRecord(createOrganizationRecord());

    $graph = WorkflowGraph::fromArray([
        'version' => 1,
        'nodes' => [
            [
                'id' => 'alert-1',
                'type' => 'alert',
                'data' => [
                    'config' => [
                        'channel' => 'sms',
                        'recipients' => ['94771234567', '94771230000'],
                        'subject' => 'Threshold exceeded',
                        'body' => 'Temperature is {{ trigger.value }}',
                        'cooldown' => [
                            'value' => 24,
                            'unit' => 'hour',
                        ],
                    ],
                ],
            ],
        ],
        'edges' => [],
    ]);

    expect(fn () => app(WorkflowNodeConfigValidator::class)->validate($workflow, $graph))
        ->not->toThrow(RuntimeException::class);
});

it('fails when alert recipients are invalid email addresses', function (): void {
    $workflow = createWorkflowRecord(createOrganizationRecord());

    $graph = WorkflowGraph::fromArray([
        'version' => 1,
        'nodes' => [
            [
                'id' => 'alert-1',
                'type' => 'alert',
                'data' => [
                    'config' => [
                        'channel' => 'email',
                        'recipients' => ['invalid-email'],
                        'subject' => 'Threshold exceeded',
                        'body' => 'Alert body',
                        'cooldown' => [
                            'value' => 30,
                            'unit' => 'minute',
                        ],
                    ],
                ],
            ],
        ],
        'edges' => [],
    ]);

    expect(fn () => app(WorkflowNodeConfigValidator::class)->validate($workflow, $graph))
        ->toThrow(RuntimeException::class, 'must be valid email addresses');
});

it('fails when sms recipients are invalid phone numbers', function (): void {
    $workflow = createWorkflowRecord(createOrganizationRecord());

    $graph = WorkflowGraph::fromArray([
        'version' => 1,
        'nodes' => [
            [
                'id' => 'alert-1',
                'type' => 'alert',
                'data' => [
                    'config' => [
                        'channel' => 'sms',
                        'recipients' => ['0771234567'],
                        'subject' => 'Threshold exceeded',
                        'body' => 'Alert body',
                        'cooldown' => [
                            'value' => 30,
                            'unit' => 'minute',
                        ],
                    ],
                ],
            ],
        ],
        'edges' => [],
    ]);

    expect(fn () => app(WorkflowNodeConfigValidator::class)->validate($workflow, $graph))
        ->toThrow(RuntimeException::class, 'must be valid phone numbers in 94XXXXXXXXX format');
});

it('fails when alert cooldown configuration is invalid', function (): void {
    $workflow = createWorkflowRecord(createOrganizationRecord());

    $graph = WorkflowGraph::fromArray([
        'version' => 1,
        'nodes' => [
            [
                'id' => 'alert-1',
                'type' => 'alert',
                'data' => [
                    'config' => [
                        'channel' => 'email',
                        'recipients' => ['alerts@example.com'],
                        'subject' => 'Threshold exceeded',
                        'body' => 'Alert body',
                        'cooldown' => [
                            'value' => 0,
                            'unit' => 'minute',
                        ],
                    ],
                ],
            ],
        ],
        'edges' => [],
    ]);

    expect(fn () => app(WorkflowNodeConfigValidator::class)->validate($workflow, $graph))
        ->toThrow(RuntimeException::class, 'cooldown must include positive value');
});
