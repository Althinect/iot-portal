<?php

declare(strict_types=1);

use App\Domain\Authorization\Permissions\RolePermission;
use App\Domain\Automation\Enums\AutomationWorkflowStatus;
use App\Domain\Automation\Models\AutomationTelemetryTrigger;
use App\Domain\Automation\Models\AutomationWorkflow;
use App\Domain\Automation\Permissions\AutomationWorkflowPermission;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Permissions\DevicePermission;
use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use App\Domain\Shared\Permissions\UserPermission;
use App\Domain\Telemetry\Permissions\DeviceTelemetryLogPermission;
use App\Filament\Portal\Resources\AutomationWorkflows\AutomationWorkflowResource;
use App\Filament\Portal\Resources\AutomationWorkflows\Pages\CreateAutomationWorkflow;
use App\Filament\Portal\Resources\AutomationWorkflows\Pages\EditAutomationDag;
use App\Filament\Portal\Resources\AutomationWorkflows\Pages\EditAutomationWorkflow;
use App\Filament\Portal\Resources\AutomationWorkflows\Pages\ListAutomationWorkflows;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->organization = Organization::factory()->create(['slug' => 'portal-automation-primary']);
    $this->otherOrganization = Organization::factory()->create(['slug' => 'portal-automation-other']);
    $this->portalUser = User::factory()->create();
    $this->portalUser->organizations()->attach($this->organization);
    $this->userWithoutOrganization = User::factory()->create();

    $permissionNames = [
        RolePermission::VIEW_ANY->value,
        UserPermission::VIEW_ANY->value,
        DevicePermission::VIEW_ANY->value,
        DevicePermission::VIEW->value,
        DeviceTelemetryLogPermission::VIEW_ANY->value,
        DeviceTelemetryLogPermission::VIEW->value,
    ];

    foreach (AutomationWorkflowPermission::cases() as $permission) {
        $permissionNames[] = $permission->value;
    }

    foreach ($permissionNames as $permissionName) {
        Permission::findOrCreate($permissionName, 'web');
    }

    $this->actingAs($this->portalUser);
    Filament::setCurrentPanel('portal');
    Filament::setTenant($this->organization);
    Filament::bootCurrentPanel();
});

it('shows only current tenant automations and authorizes organization members to manage them', function (): void {
    $workflow = AutomationWorkflow::factory()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Primary workflow',
    ]);
    $managedWorkflow = AutomationWorkflow::factory()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Managed workflow',
        'is_managed' => true,
        'managed_type' => 'threshold_policy',
    ]);
    $otherWorkflow = AutomationWorkflow::withoutEvents(fn (): AutomationWorkflow => AutomationWorkflow::factory()->create([
        'organization_id' => $this->otherOrganization->id,
        'name' => 'Other workflow',
    ]));

    $tenantQuery = AutomationWorkflowResource::getEloquentQuery();
    $expectedWorkflowIds = collect([$workflow->id, $managedWorkflow->id])->sort()->values()->all();
    $actualWorkflowIds = $tenantQuery->pluck('automation_workflows.id')->sort()->values()->all();

    expect(Filament::getTenant()?->getKey())->toBe($this->organization->id)
        ->and($actualWorkflowIds)->toBe($expectedWorkflowIds);

    livewire(ListAutomationWorkflows::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$workflow, $managedWorkflow])
        ->assertCanNotSeeTableRecords([$otherWorkflow]);

    expect($this->portalUser->can('viewAny', AutomationWorkflow::class))->toBeTrue()
        ->and($this->portalUser->can('create', AutomationWorkflow::class))->toBeTrue()
        ->and($this->portalUser->can('view', $workflow))->toBeTrue()
        ->and($this->portalUser->can('update', $workflow))->toBeTrue()
        ->and($this->portalUser->can('delete', $workflow))->toBeTrue()
        ->and(AutomationWorkflowResource::canEdit($managedWorkflow))->toBeFalse()
        ->and(AutomationWorkflowResource::canDelete($managedWorkflow))->toBeFalse()
        ->and($this->portalUser->can('view', $otherWorkflow))->toBeFalse()
        ->and($this->portalUser->can('update', $otherWorkflow))->toBeFalse()
        ->and($this->userWithoutOrganization->can('viewAny', AutomationWorkflow::class))->toBeFalse()
        ->and($this->userWithoutOrganization->can('create', AutomationWorkflow::class))->toBeFalse();
});

it('creates and edits an automation inside the current tenant', function (): void {
    $component = livewire(CreateAutomationWorkflow::class)
        ->fillForm([
            'name' => 'Portal Alarm Workflow',
            'slug' => 'Portal Alarm Workflow',
            'status' => AutomationWorkflowStatus::Draft->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $workflow = AutomationWorkflow::query()->sole();

    $component->assertRedirect(AutomationWorkflowResource::getUrl('dag-editor', ['record' => $workflow]));

    livewire(EditAutomationWorkflow::class, ['record' => $workflow->id])
        ->fillForm([
            'name' => 'Portal Alarm Workflow Updated',
            'slug' => 'Portal Alarm Workflow Updated',
            'status' => AutomationWorkflowStatus::Active->value,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $workflow->refresh();

    expect($workflow->organization_id)->toBe($this->organization->id)
        ->and($workflow->name)->toBe('Portal Alarm Workflow Updated')
        ->and($workflow->slug)->toBe('portal-alarm-workflow-updated')
        ->and($workflow->status)->toBe(AutomationWorkflowStatus::Active)
        ->and($workflow->created_by)->toBe($this->portalUser->id)
        ->and($workflow->updated_by)->toBe($this->portalUser->id);
});

it('denies cross-tenant automation routes', function (): void {
    $otherWorkflow = AutomationWorkflow::withoutEvents(fn (): AutomationWorkflow => AutomationWorkflow::factory()->create([
        'organization_id' => $this->otherOrganization->id,
    ]));

    foreach (['view', 'edit', 'dag-editor'] as $page) {
        $this->get(AutomationWorkflowResource::getUrl(
            $page,
            ['record' => $otherWorkflow],
            panel: 'portal',
            tenant: $this->organization,
        ))->assertNotFound();
    }
});

it('allows a portal user to configure a dag using only current tenant devices', function (): void {
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
        'organization_id' => $this->organization->id,
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
        'organization_id' => $this->organization->id,
        'device_profile_version_id' => $targetProfileVersion->id,
    ]);
    $otherDevice = Device::withoutEvents(fn (): Device => Device::factory()->create([
        'organization_id' => $this->otherOrganization->id,
        'device_profile_version_id' => $targetProfileVersion->id,
    ]));
    $workflow = AutomationWorkflow::factory()->create([
        'organization_id' => $this->organization->id,
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
                            'right' => 250,
                        ],
                        'json_logic' => [
                            '>' => [
                                ['var' => 'trigger.value'],
                                250,
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

    $component = livewire(EditAutomationDag::class, ['record' => $workflow->id]);
    $commandOptions = $component->instance()->getCommandNodeOptions();
    $commandDeviceIds = collect($commandOptions['devices'])->pluck('id')->all();

    expect($commandDeviceIds)
        ->toContain($targetDevice->id)
        ->not->toContain($otherDevice->id);

    $component
        ->call('saveGraph', $graph)
        ->assertNotified('DAG saved');

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
