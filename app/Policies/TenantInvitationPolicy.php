<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Authorization\Services\TenantAuthorization;
use App\Domain\Shared\Models\TenantInvitation;
use App\Domain\Shared\Models\User;
use App\Domain\Shared\Permissions\OrganizationMemberPermission;

class TenantInvitationPolicy
{
    public function __construct(private TenantAuthorization $authorization) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->allows($user, OrganizationMemberPermission::VIEW_ANY);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, TenantInvitation $tenantInvitation): bool
    {
        return $this->authorization->allows(
            $user,
            OrganizationMemberPermission::VIEW_ANY,
            $tenantInvitation->organization_id,
        );
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->authorization->allows($user, OrganizationMemberPermission::INVITE);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, TenantInvitation $tenantInvitation): bool
    {
        return $this->authorization->allows(
            $user,
            OrganizationMemberPermission::INVITE,
            $tenantInvitation->organization_id,
        );
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, TenantInvitation $tenantInvitation): bool
    {
        return $this->authorization->allows(
            $user,
            OrganizationMemberPermission::INVITE,
            $tenantInvitation->organization_id,
        );
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, TenantInvitation $tenantInvitation): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, TenantInvitation $tenantInvitation): bool
    {
        return false;
    }
}
