<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Authorization\Services\TenantAuthorization;
use App\Domain\DeviceControl\Models\DeviceCommandLog;
use App\Domain\DeviceControl\Permissions\DeviceCommandLogPermission;
use App\Domain\Shared\Models\User;

class DeviceCommandLogPolicy
{
    public function __construct(private TenantAuthorization $authorization) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->allows($user, DeviceCommandLogPermission::VIEW_ANY);
    }

    public function view(User $user, DeviceCommandLog $model): bool
    {
        return $this->allowsForDevice($user, DeviceCommandLogPermission::VIEW, $model);
    }

    public function create(User $user): bool
    {
        return $this->authorization->allows($user, DeviceCommandLogPermission::CREATE);
    }

    public function update(User $user, DeviceCommandLog $model): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, DeviceCommandLog $model): bool
    {
        return $user->isSuperAdmin();
    }

    public function restore(User $user, DeviceCommandLog $model): bool
    {
        return $user->isSuperAdmin();
    }

    public function forceDelete(User $user, DeviceCommandLog $model): bool
    {
        return $user->isSuperAdmin();
    }

    private function allowsForDevice(
        User $user,
        DeviceCommandLogPermission $permission,
        DeviceCommandLog $model,
    ): bool {
        $model->loadMissing('device');

        return $model->device !== null
            && $this->authorization->allows($user, $permission, $model->device->organization_id);
    }
}
