<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Permissions\DevicePermission;
use App\Domain\Shared\Models\User;

class DevicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->organizations()->exists();
    }

    public function view(User $user, Device $model): bool
    {
        return $user->organizations()->whereKey($model->organization_id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo(DevicePermission::CREATE);
    }

    public function update(User $user, Device $model): bool
    {
        return $user->hasPermissionTo(DevicePermission::UPDATE);
    }

    public function delete(User $user, Device $model): bool
    {
        return $user->hasPermissionTo(DevicePermission::DELETE);
    }

    public function restore(User $user, Device $model): bool
    {
        return $user->hasPermissionTo(DevicePermission::RESTORE);
    }

    public function forceDelete(User $user, Device $model): bool
    {
        return $user->hasPermissionTo(DevicePermission::FORCE_DELETE);
    }
}
