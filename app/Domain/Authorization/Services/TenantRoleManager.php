<?php

declare(strict_types=1);

namespace App\Domain\Authorization\Services;

use App\Domain\Alerts\Permissions\AlertPermission;
use App\Domain\Authorization\Enums\TenantRole;
use App\Domain\Authorization\Models\Role;
use App\Domain\Automation\Permissions\AutomationNotificationProfilePermission;
use App\Domain\Automation\Permissions\AutomationThresholdPolicyPermission;
use App\Domain\Automation\Permissions\AutomationWorkflowPermission;
use App\Domain\DeviceControl\Permissions\DeviceCommandLogPermission;
use App\Domain\DeviceControl\Permissions\DeviceDesiredStatePermission;
use App\Domain\DeviceManagement\Permissions\DevicePermission;
use App\Domain\DeviceProfile\Permissions\DeviceProfilePermission;
use App\Domain\DeviceProfile\Permissions\DeviceProfileVersionPermission;
use App\Domain\IoTDashboard\Permissions\IoTDashboardPermission;
use App\Domain\IoTDashboard\Permissions\IoTDashboardWidgetPermission;
use App\Domain\Reporting\Permissions\ReportRunPermission;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use App\Domain\Shared\Permissions\EntityPermission;
use App\Domain\Shared\Permissions\OrganizationMemberPermission;
use App\Domain\Shared\Permissions\OrganizationPermission;
use App\Domain\Telemetry\Permissions\DeviceTelemetryLogPermission;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

final class TenantRoleManager
{
    /**
     * @return Collection<int, Role>
     */
    public function syncForOrganization(Organization $organization): Collection
    {
        $previousTeamId = getPermissionsTeamId();
        setPermissionsTeamId($organization->getKey());

        try {
            $roles = collect(TenantRole::cases())
                ->mapWithKeys(function (TenantRole $tenantRole) use ($organization): array {
                    $role = $this->resolveRole($organization, $tenantRole);
                    $permissions = collect($this->permissionsFor($tenantRole))
                        ->map(fn (string $permission): Permission => Permission::findOrCreate($permission, 'web'));

                    $role->syncPermissions($permissions);

                    return [$tenantRole->value => $role->fresh() ?? $role];
                });

            $this->assignViewerToMembersWithoutFixedRole($organization, $roles->get(TenantRole::Viewer->value));

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return $roles->values();
        } finally {
            setPermissionsTeamId($previousTeamId);
        }
    }

    public function assign(User $user, Organization $organization, TenantRole $tenantRole): void
    {
        $roles = $this->syncForOrganization($organization);
        $targetRole = $roles->first(
            fn (Role $role): bool => $role->tenant_role_key === $tenantRole,
        );

        if (! $targetRole instanceof Role) {
            return;
        }

        $previousTeamId = getPermissionsTeamId();
        setPermissionsTeamId($organization->getKey());

        try {
            $fixedRoleIds = $roles->pluck('id')->all();
            $user->roles()
                ->wherePivot('organization_id', $organization->getKey())
                ->detach($fixedRoleIds);
            $user->assignRole($targetRole);
            $user->unsetRelation('roles');
        } finally {
            setPermissionsTeamId($previousTeamId);
        }
    }

    /**
     * @return list<string>
     */
    public function permissionsFor(TenantRole $tenantRole): array
    {
        $viewerPermissions = [
            DevicePermission::VIEW_ANY->value,
            DevicePermission::VIEW->value,
            DeviceTelemetryLogPermission::VIEW_ANY->value,
            DeviceTelemetryLogPermission::VIEW->value,
            IoTDashboardPermission::VIEW_ANY->value,
            IoTDashboardPermission::VIEW->value,
            IoTDashboardWidgetPermission::VIEW->value,
            AutomationWorkflowPermission::VIEW_ANY->value,
            AutomationWorkflowPermission::VIEW->value,
            AutomationThresholdPolicyPermission::VIEW_ANY->value,
            AutomationThresholdPolicyPermission::VIEW->value,
            AutomationNotificationProfilePermission::VIEW_ANY->value,
            AutomationNotificationProfilePermission::VIEW->value,
            AlertPermission::VIEW_ANY->value,
            AlertPermission::VIEW->value,
            ReportRunPermission::VIEW_ANY->value,
            ReportRunPermission::VIEW->value,
            ReportRunPermission::DOWNLOAD->value,
            EntityPermission::VIEW_ANY->value,
            EntityPermission::VIEW->value,
            DeviceProfilePermission::VIEW_ANY->value,
            DeviceProfilePermission::VIEW->value,
            DeviceProfileVersionPermission::VIEW_ANY->value,
            DeviceProfileVersionPermission::VIEW->value,
        ];

        $operatorPermissions = [
            ...$viewerPermissions,
            DevicePermission::CONTROL->value,
            DevicePermission::VIEW_DIAGNOSTICS->value,
            DeviceDesiredStatePermission::VIEW_ANY->value,
            DeviceDesiredStatePermission::VIEW->value,
            DeviceDesiredStatePermission::CREATE->value,
            DeviceDesiredStatePermission::UPDATE->value,
            DeviceCommandLogPermission::VIEW_ANY->value,
            DeviceCommandLogPermission::VIEW->value,
            DeviceCommandLogPermission::CREATE->value,
            IoTDashboardPermission::CREATE->value,
            IoTDashboardPermission::UPDATE->value,
            IoTDashboardPermission::ARCHIVE->value,
            IoTDashboardPermission::RESTORE->value,
            IoTDashboardWidgetPermission::CREATE->value,
            IoTDashboardWidgetPermission::UPDATE->value,
            IoTDashboardWidgetPermission::DELETE->value,
            IoTDashboardWidgetPermission::LAYOUT->value,
            AutomationWorkflowPermission::CREATE->value,
            AutomationWorkflowPermission::UPDATE->value,
            AutomationWorkflowPermission::PUBLISH->value,
            AutomationWorkflowPermission::ARCHIVE->value,
            AutomationWorkflowPermission::RESTORE->value,
            AutomationThresholdPolicyPermission::CREATE->value,
            AutomationThresholdPolicyPermission::UPDATE->value,
            AutomationThresholdPolicyPermission::ARCHIVE->value,
            AlertPermission::ACKNOWLEDGE->value,
            ReportRunPermission::CREATE->value,
        ];

        return match ($tenantRole) {
            TenantRole::Viewer => $viewerPermissions,
            TenantRole::Operator => $operatorPermissions,
            TenantRole::TenantAdmin => [
                ...$operatorPermissions,
                DevicePermission::CREATE->value,
                DevicePermission::UPDATE->value,
                DevicePermission::PROVISION->value,
                DevicePermission::MANAGE_CREDENTIALS->value,
                DevicePermission::DECOMMISSION->value,
                DevicePermission::REACTIVATE->value,
                DeviceProfilePermission::CREATE->value,
                DeviceProfilePermission::UPDATE->value,
                DeviceProfilePermission::DELETE->value,
                DeviceProfilePermission::RESTORE->value,
                DeviceProfileVersionPermission::CREATE->value,
                DeviceProfileVersionPermission::UPDATE->value,
                DeviceProfileVersionPermission::DELETE->value,
                DeviceProfileVersionPermission::ACTIVATE->value,
                AutomationNotificationProfilePermission::CREATE->value,
                AutomationNotificationProfilePermission::UPDATE->value,
                AutomationNotificationProfilePermission::ARCHIVE->value,
                EntityPermission::CREATE->value,
                EntityPermission::UPDATE->value,
                EntityPermission::ARCHIVE->value,
                EntityPermission::RESTORE->value,
                OrganizationMemberPermission::VIEW_ANY->value,
                OrganizationMemberPermission::INVITE->value,
                OrganizationMemberPermission::UPDATE_ROLE->value,
                OrganizationMemberPermission::DETACH->value,
                OrganizationPermission::UPDATE_SETTINGS->value,
                ReportRunPermission::MANAGE_SETTINGS->value,
            ],
        };
    }

    private function resolveRole(Organization $organization, TenantRole $tenantRole): Role
    {
        $roleId = DB::table('roles')
            ->where('organization_id', $organization->getKey())
            ->where('tenant_role_key', $tenantRole->value)
            ->value('id');

        $role = is_numeric($roleId)
            ? Role::query()->withoutGlobalScopes()->find((int) $roleId)
            : null;

        if (! $role instanceof Role) {
            $legacyNames = match ($tenantRole) {
                TenantRole::Viewer => ['viewer', 'portal-viewer'],
                TenantRole::Operator => ['operator'],
                TenantRole::TenantAdmin => ['tenant-admin', 'admin'],
            };

            $roleId = collect($legacyNames)
                ->map(fn (string $roleName): mixed => DB::table('roles')
                    ->where('organization_id', $organization->getKey())
                    ->where('name', $roleName)
                    ->value('id'))
                ->first(fn (mixed $candidate): bool => is_numeric($candidate));

            $role = is_numeric($roleId)
                ? Role::query()->withoutGlobalScopes()->find((int) $roleId)
                : null;
        }

        if (! $role instanceof Role) {
            DB::table('roles')->insertOrIgnore([
                'organization_id' => $organization->getKey(),
                'name' => $tenantRole->value,
                'guard_name' => 'web',
                'tenant_role_key' => $tenantRole->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $role = Role::query()
                ->withoutGlobalScopes()
                ->where('organization_id', $organization->getKey())
                ->where('name', $tenantRole->value)
                ->firstOrFail();
        }

        $role->name = $tenantRole->value;
        $role->tenant_role_key = $tenantRole;
        $role->save();

        return $role;
    }

    private function assignViewerToMembersWithoutFixedRole(Organization $organization, ?Role $viewerRole): void
    {
        if (! $viewerRole instanceof Role) {
            return;
        }

        $fixedRoleIds = Role::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->getKey())
            ->whereNotNull('tenant_role_key')
            ->pluck('id');

        $organization->users()
            ->where('is_super_admin', false)
            ->get()
            ->each(function (User $user) use ($fixedRoleIds, $viewerRole, $organization): void {
                $hasFixedRole = $user->roles()
                    ->wherePivot('organization_id', $organization->getKey())
                    ->whereIn('roles.id', $fixedRoleIds)
                    ->exists();

                if (! $hasFixedRole) {
                    $user->assignRole($viewerRole);
                }
            });
    }
}
