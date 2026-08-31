<?php

declare(strict_types=1);

use App\Domain\Authorization\Enums\TenantRole;
use App\Domain\Authorization\Models\Role;
use App\Domain\Authorization\Services\TenantRoleManager;
use App\Domain\Automation\Models\AutomationWorkflow;
use App\Domain\DeviceControl\Models\DeviceCommandLog;
use App\Domain\DeviceControl\Models\DeviceDesiredState;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Models\DeviceProfile;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\IoTDashboard\Models\IoTDashboard;
use App\Domain\Reporting\Models\ReportRun;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(function (): void {
    setPermissionsTeamId(null);
});

it('creates idempotent protected roles and preserves custom tenant roles during assignment', function (): void {
    $organization = Organization::factory()->create();
    $member = User::factory()->create();
    $member->organizations()->attach($organization);
    $roleManager = app(TenantRoleManager::class);

    $initialRoles = $roleManager->syncForOrganization($organization);
    $initialRoleIds = $initialRoles->pluck('id')->sort()->values()->all();

    setPermissionsTeamId($organization->id);

    expect($initialRoles)->toHaveCount(3)
        ->and($initialRoles->pluck('tenant_role_key')->all())
        ->toContain(TenantRole::Viewer, TenantRole::Operator, TenantRole::TenantAdmin)
        ->and($member->fresh()->hasRole(TenantRole::Viewer->value))
        ->toBeTrue();

    $customRole = Role::query()->create([
        'organization_id' => $organization->id,
        'name' => 'auditor',
        'guard_name' => 'web',
    ]);
    $member->assignRole($customRole);

    $roleManager->assign($member, $organization, TenantRole::Operator);

    $assignedRoleNames = $member->roles()
        ->wherePivot('organization_id', $organization->id)
        ->pluck('roles.name')
        ->sort()
        ->values()
        ->all();

    expect($assignedRoleNames)->toBe(['auditor', TenantRole::Operator->value])
        ->and($roleManager->syncForOrganization($organization)->pluck('id')->sort()->values()->all())
        ->toBe($initialRoleIds)
        ->and(
            Role::query()
                ->where('organization_id', $organization->id)
                ->whereNotNull('tenant_role_key')
                ->count(),
        )->toBe(3);
});

it('isolates fixed-role permissions by the active organization', function (): void {
    $operatorOrganization = Organization::factory()->create();
    $viewerOrganization = Organization::factory()->create();
    $user = User::factory()->create();
    $user->organizations()->attach([$operatorOrganization->id, $viewerOrganization->id]);
    $roleManager = app(TenantRoleManager::class);
    $roleManager->assign($user, $operatorOrganization, TenantRole::Operator);
    $roleManager->assign($user, $viewerOrganization, TenantRole::Viewer);

    $operatorDevice = Device::factory()->create(['organization_id' => $operatorOrganization->id]);
    $viewerDevice = Device::factory()->create(['organization_id' => $viewerOrganization->id]);
    $operatorDashboard = IoTDashboard::factory()->create(['organization_id' => $operatorOrganization->id]);
    $viewerDashboard = IoTDashboard::factory()->create(['organization_id' => $viewerOrganization->id]);
    $operatorWorkflow = AutomationWorkflow::factory()->create([
        'organization_id' => $operatorOrganization->id,
    ]);
    $desiredState = DeviceDesiredState::factory()->create(['device_id' => $operatorDevice->id]);
    $commandLog = DeviceCommandLog::factory()->create(['device_id' => $operatorDevice->id]);

    setPermissionsTeamId($operatorOrganization->id);

    expect($user->can('viewAny', Device::class))->toBeTrue()
        ->and($user->can('view', $operatorDevice))->toBeTrue()
        ->and($user->can('view', $viewerDevice))->toBeFalse()
        ->and($user->can('control', $operatorDevice))->toBeTrue()
        ->and($user->can('provision', Device::class))->toBeFalse()
        ->and($user->can('create', IoTDashboard::class))->toBeTrue()
        ->and($user->can('update', $operatorDashboard))->toBeTrue()
        ->and($user->can('update', $viewerDashboard))->toBeFalse()
        ->and($user->can('create', AutomationWorkflow::class))->toBeTrue()
        ->and($user->can('publish', $operatorWorkflow))->toBeTrue()
        ->and($user->can('view', $desiredState))->toBeTrue()
        ->and($user->can('view', $commandLog))->toBeTrue()
        ->and($user->can('create', ReportRun::class))->toBeTrue();

    setPermissionsTeamId($viewerOrganization->id);

    expect($user->can('view', $viewerDevice))->toBeTrue()
        ->and($user->can('view', $operatorDevice))->toBeFalse()
        ->and($user->can('control', $viewerDevice))->toBeFalse()
        ->and($user->can('create', IoTDashboard::class))->toBeFalse()
        ->and($user->can('update', $viewerDashboard))->toBeFalse()
        ->and($user->can('create', AutomationWorkflow::class))->toBeFalse()
        ->and($user->can('create', ReportRun::class))->toBeFalse();

    setPermissionsTeamId(null);

    expect($user->can('viewAny', Device::class))->toBeFalse();
});

it('allows tenant admins to manage private contracts but keeps global contracts read only', function (): void {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $tenantAdmin = User::factory()->create();
    $tenantAdmin->organizations()->attach([$organization->id, $otherOrganization->id]);
    $roleManager = app(TenantRoleManager::class);
    $roleManager->assign($tenantAdmin, $organization, TenantRole::TenantAdmin);
    $roleManager->assign($tenantAdmin, $otherOrganization, TenantRole::Viewer);

    $device = Device::factory()->create(['organization_id' => $organization->id]);
    $globalProfile = DeviceProfile::factory()->create(['organization_id' => null]);
    $privateProfile = DeviceProfile::factory()->create(['organization_id' => $organization->id]);
    $otherProfile = DeviceProfile::factory()->create(['organization_id' => $otherOrganization->id]);
    $draftVersion = DeviceProfileVersion::factory()->create([
        'device_profile_id' => $privateProfile->id,
        'status' => DeviceProfileVersion::STATUS_DRAFT,
    ]);
    $activeVersion = DeviceProfileVersion::factory()->create([
        'device_profile_id' => $privateProfile->id,
        'version' => 2,
        'status' => DeviceProfileVersion::STATUS_ACTIVE,
    ]);

    setPermissionsTeamId($organization->id);

    expect($tenantAdmin->can('provision', Device::class))->toBeTrue()
        ->and($tenantAdmin->can('updateSettings', $organization))->toBeTrue()
        ->and($tenantAdmin->can('updateSettings', $otherOrganization))->toBeFalse()
        ->and($tenantAdmin->can('manageCredentials', $device))->toBeTrue()
        ->and($tenantAdmin->can('decommission', $device))->toBeTrue()
        ->and($tenantAdmin->can('view', $globalProfile))->toBeTrue()
        ->and($tenantAdmin->can('update', $globalProfile))->toBeFalse()
        ->and($tenantAdmin->can('update', $privateProfile))->toBeTrue()
        ->and($tenantAdmin->can('update', $otherProfile))->toBeFalse()
        ->and($tenantAdmin->can('update', $draftVersion))->toBeTrue()
        ->and($tenantAdmin->can('activate', $draftVersion))->toBeTrue()
        ->and($tenantAdmin->can('update', $activeVersion))->toBeFalse();

    setPermissionsTeamId($otherOrganization->id);

    expect($tenantAdmin->can('update', $otherProfile))->toBeFalse()
        ->and($tenantAdmin->can('view', $globalProfile))->toBeTrue();
});

it('keeps super administrators authorized without an active tenant', function (): void {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);

    setPermissionsTeamId(null);

    expect($superAdmin->can('create', IoTDashboard::class))->toBeTrue()
        ->and($superAdmin->can('provision', Device::class))->toBeTrue();
});
