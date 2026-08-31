<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Authorization\Services\TenantAuthorization;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use App\Domain\Shared\Permissions\OrganizationPermission;

class OrganizationPolicy
{
    public function __construct(private TenantAuthorization $authorization) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->allows($user, OrganizationPermission::VIEW_ANY);
    }

    public function view(User $user, Organization $model): bool
    {
        return $this->authorization->allows($user, OrganizationPermission::VIEW, $model->id);
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, Organization $model): bool
    {
        return $this->authorization->allows($user, OrganizationPermission::UPDATE, $model->id);
    }

    public function delete(User $user, Organization $model): bool
    {
        return $user->isSuperAdmin();
    }

    public function restore(User $user, Organization $model): bool
    {
        return $user->isSuperAdmin();
    }

    public function forceDelete(User $user, Organization $model): bool
    {
        return $user->isSuperAdmin();
    }

    public function updateSettings(User $user, Organization $model): bool
    {
        return $this->authorization->allows(
            $user,
            OrganizationPermission::UPDATE_SETTINGS,
            $model->id,
        );
    }
}
