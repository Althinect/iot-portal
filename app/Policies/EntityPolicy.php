<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Authorization\Services\TenantAuthorization;
use App\Domain\Shared\Models\Entity;
use App\Domain\Shared\Models\User;
use App\Domain\Shared\Permissions\EntityPermission;

class EntityPolicy
{
    public function __construct(private TenantAuthorization $authorization) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->allows($user, EntityPermission::VIEW_ANY);
    }

    public function view(User $user, Entity $entity): bool
    {
        return $this->authorization->allows($user, EntityPermission::VIEW, $entity->organization_id);
    }

    public function create(User $user): bool
    {
        return $this->authorization->allows($user, EntityPermission::CREATE);
    }

    public function update(User $user, Entity $entity): bool
    {
        return $this->authorization->allows($user, EntityPermission::UPDATE, $entity->organization_id);
    }

    public function delete(User $user, Entity $entity): bool
    {
        return $this->authorization->allows($user, EntityPermission::ARCHIVE, $entity->organization_id);
    }

    public function restore(User $user, Entity $entity): bool
    {
        return $this->authorization->allows($user, EntityPermission::RESTORE, $entity->organization_id);
    }

    public function forceDelete(User $user, Entity $entity): bool
    {
        return $user->isSuperAdmin();
    }
}
