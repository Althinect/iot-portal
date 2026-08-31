<?php

declare(strict_types=1);

namespace App\Domain\Shared\Services;

use App\Domain\Authorization\Enums\TenantRole;
use App\Domain\Authorization\Services\TenantAuthorization;
use App\Domain\Authorization\Services\TenantRoleManager;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use App\Domain\Shared\Permissions\OrganizationMemberPermission;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TenantMemberManager
{
    public function __construct(
        private TenantAuthorization $authorization,
        private TenantRoleManager $roleManager,
    ) {}

    public function changeRole(
        User $actor,
        User $member,
        Organization $organization,
        TenantRole $tenantRole,
    ): void {
        $this->authorize($actor, $organization, OrganizationMemberPermission::UPDATE_ROLE);
        $this->ensureMembership($member, $organization);

        if (
            $tenantRole !== TenantRole::TenantAdmin
            && $this->hasRole($member, $organization, TenantRole::TenantAdmin)
            && $this->tenantAdminCount($organization) <= 1
        ) {
            throw ValidationException::withMessages([
                'tenant_role_key' => 'Assign another Tenant Admin before changing the final administrator.',
            ]);
        }

        $this->roleManager->assign($member, $organization, $tenantRole);
    }

    public function detach(User $actor, User $member, Organization $organization): void
    {
        $this->authorize($actor, $organization, OrganizationMemberPermission::DETACH);
        $this->ensureMembership($member, $organization);

        if ($actor->is($member)) {
            throw ValidationException::withMessages([
                'member' => 'You cannot remove your own organization membership.',
            ]);
        }

        if (
            $this->hasRole($member, $organization, TenantRole::TenantAdmin)
            && $this->tenantAdminCount($organization) <= 1
        ) {
            throw ValidationException::withMessages([
                'member' => 'Assign another Tenant Admin before removing the final administrator.',
            ]);
        }

        DB::transaction(function () use ($member, $organization): void {
            DB::table(config('permission.table_names.model_has_roles'))
                ->where('organization_id', $organization->id)
                ->where('model_type', $member->getMorphClass())
                ->where('model_id', $member->id)
                ->delete();

            $organization->users()->detach($member->id);
            $member->unsetRelation('roles');
        });
    }

    public function roleFor(User $member, Organization $organization): ?TenantRole
    {
        $roleKey = DB::table(config('permission.table_names.model_has_roles').' as model_roles')
            ->join('roles', 'roles.id', '=', 'model_roles.role_id')
            ->where('model_roles.organization_id', $organization->id)
            ->where('model_roles.model_type', $member->getMorphClass())
            ->where('model_roles.model_id', $member->id)
            ->whereNotNull('roles.tenant_role_key')
            ->value('roles.tenant_role_key');

        return is_string($roleKey) ? TenantRole::tryFrom($roleKey) : null;
    }

    private function authorize(
        User $actor,
        Organization $organization,
        OrganizationMemberPermission $permission,
    ): void {
        if (! $this->authorization->allows($actor, $permission, $organization->id)) {
            throw new AuthorizationException;
        }
    }

    private function ensureMembership(User $member, Organization $organization): void
    {
        if (! $member->organizations()->whereKey($organization->id)->exists()) {
            throw ValidationException::withMessages([
                'member' => 'The selected user is not a member of this organization.',
            ]);
        }
    }

    private function hasRole(User $member, Organization $organization, TenantRole $tenantRole): bool
    {
        return $this->roleFor($member, $organization) === $tenantRole;
    }

    private function tenantAdminCount(Organization $organization): int
    {
        return DB::table(config('permission.table_names.model_has_roles').' as model_roles')
            ->join('roles', 'roles.id', '=', 'model_roles.role_id')
            ->where('model_roles.organization_id', $organization->id)
            ->where('roles.tenant_role_key', TenantRole::TenantAdmin->value)
            ->distinct('model_roles.model_id')
            ->count('model_roles.model_id');
    }
}
