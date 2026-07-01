<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\DeviceProfile\Models\DeviceProfile;
use App\Domain\DeviceProfile\Permissions\DeviceProfilePermission;
use App\Domain\Shared\Models\User;

class DeviceProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(DeviceProfilePermission::VIEW_ANY);
    }

    public function view(User $user, DeviceProfile $deviceProfile): bool
    {
        return $user->hasPermissionTo(DeviceProfilePermission::VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo(DeviceProfilePermission::CREATE);
    }

    public function update(User $user, DeviceProfile $deviceProfile): bool
    {
        return $user->hasPermissionTo(DeviceProfilePermission::UPDATE);
    }

    public function delete(User $user, DeviceProfile $deviceProfile): bool
    {
        return $user->hasPermissionTo(DeviceProfilePermission::DELETE);
    }

    public function restore(User $user, DeviceProfile $deviceProfile): bool
    {
        return $user->hasPermissionTo(DeviceProfilePermission::RESTORE);
    }

    public function forceDelete(User $user, DeviceProfile $deviceProfile): bool
    {
        return $user->hasPermissionTo(DeviceProfilePermission::FORCE_DELETE);
    }
}
