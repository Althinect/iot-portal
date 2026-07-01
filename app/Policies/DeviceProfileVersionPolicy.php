<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Permissions\DeviceProfileVersionPermission;
use App\Domain\Shared\Models\User;

class DeviceProfileVersionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(DeviceProfileVersionPermission::VIEW_ANY);
    }

    public function view(User $user, DeviceProfileVersion $deviceProfileVersion): bool
    {
        return $user->hasPermissionTo(DeviceProfileVersionPermission::VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo(DeviceProfileVersionPermission::CREATE);
    }

    public function update(User $user, DeviceProfileVersion $deviceProfileVersion): bool
    {
        return $user->hasPermissionTo(DeviceProfileVersionPermission::UPDATE);
    }

    public function delete(User $user, DeviceProfileVersion $deviceProfileVersion): bool
    {
        return $user->hasPermissionTo(DeviceProfileVersionPermission::DELETE);
    }

    public function restore(User $user, DeviceProfileVersion $deviceProfileVersion): bool
    {
        return $user->hasPermissionTo(DeviceProfileVersionPermission::RESTORE);
    }

    public function forceDelete(User $user, DeviceProfileVersion $deviceProfileVersion): bool
    {
        return $user->hasPermissionTo(DeviceProfileVersionPermission::FORCE_DELETE);
    }
}
