<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Authorization\Services\TenantAuthorization;
use App\Domain\DeviceControl\Models\DeviceDesiredState;
use App\Domain\DeviceControl\Permissions\DeviceDesiredStatePermission;
use App\Domain\Shared\Models\User;

class DeviceDesiredStatePolicy
{
    public function __construct(private TenantAuthorization $authorization) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->allows($user, DeviceDesiredStatePermission::VIEW_ANY);
    }

    public function view(User $user, DeviceDesiredState $model): bool
    {
        return $this->allowsForDevice($user, DeviceDesiredStatePermission::VIEW, $model);
    }

    public function create(User $user): bool
    {
        return $this->authorization->allows($user, DeviceDesiredStatePermission::CREATE);
    }

    public function update(User $user, DeviceDesiredState $model): bool
    {
        return $this->allowsForDevice($user, DeviceDesiredStatePermission::UPDATE, $model);
    }

    public function delete(User $user, DeviceDesiredState $model): bool
    {
        return $user->isSuperAdmin();
    }

    public function restore(User $user, DeviceDesiredState $model): bool
    {
        return $user->isSuperAdmin();
    }

    public function forceDelete(User $user, DeviceDesiredState $model): bool
    {
        return $user->isSuperAdmin();
    }

    private function allowsForDevice(
        User $user,
        DeviceDesiredStatePermission $permission,
        DeviceDesiredState $model,
    ): bool {
        $model->loadMissing('device');

        return $model->device !== null
            && $this->authorization->allows($user, $permission, $model->device->organization_id);
    }
}
