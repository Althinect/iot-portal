<?php

declare(strict_types=1);

namespace App\Domain\Authorization\Services;

use App\Domain\Shared\Models\User;
use BackedEnum;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

final class TenantAuthorization
{
    public function allows(
        User $user,
        BackedEnum|string $permission,
        int|string|null $organizationId = null,
    ): bool {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $activeOrganizationId = $this->activeOrganizationId();

        if ($activeOrganizationId === null) {
            return false;
        }

        if ($organizationId !== null && (string) $organizationId !== (string) $activeOrganizationId) {
            return false;
        }

        if (! $user->organizations()->whereKey($activeOrganizationId)->exists()) {
            return false;
        }

        $user->unsetRelation('roles');
        $user->unsetRelation('permissions');

        $permissionName = $permission instanceof BackedEnum
            ? (string) $permission->value
            : $permission;

        try {
            return $user->hasPermissionTo($permissionName, 'web');
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

    public function allowsGlobalOrTenantRecord(
        User $user,
        BackedEnum|string $permission,
        int|string|null $organizationId,
    ): bool {
        if ($organizationId === null) {
            return $this->allows($user, $permission);
        }

        return $this->allows($user, $permission, $organizationId);
    }

    public function activeOrganizationId(): ?int
    {
        $organizationId = getPermissionsTeamId();

        if (! is_int($organizationId) && ! is_string($organizationId)) {
            return null;
        }

        if (! ctype_digit((string) $organizationId)) {
            return null;
        }

        return (int) $organizationId;
    }
}
