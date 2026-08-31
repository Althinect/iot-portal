<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Authorization\Services\TenantAuthorization;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Permissions\DevicePermission;
use App\Domain\Shared\Models\User;

class DevicePolicy
{
    public function __construct(private TenantAuthorization $authorization) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->allows($user, DevicePermission::VIEW_ANY);
    }

    public function view(User $user, Device $model): bool
    {
        return $this->authorization->allows($user, DevicePermission::VIEW, $model->organization_id);
    }

    public function create(User $user): bool
    {
        return $this->authorization->allows($user, DevicePermission::CREATE);
    }

    public function update(User $user, Device $model): bool
    {
        return $this->authorization->allows($user, DevicePermission::UPDATE, $model->organization_id);
    }

    public function delete(User $user, Device $model): bool
    {
        return $this->authorization->allows($user, DevicePermission::DELETE, $model->organization_id);
    }

    public function restore(User $user, Device $model): bool
    {
        return $this->authorization->allows($user, DevicePermission::RESTORE, $model->organization_id);
    }

    public function forceDelete(User $user, Device $model): bool
    {
        return $user->isSuperAdmin();
    }

    public function control(User $user, Device $model): bool
    {
        return $this->authorization->allows($user, DevicePermission::CONTROL, $model->organization_id);
    }

    public function viewDiagnostics(User $user, Device $model): bool
    {
        return $this->authorization->allows($user, DevicePermission::VIEW_DIAGNOSTICS, $model->organization_id);
    }

    public function provision(User $user): bool
    {
        return $this->authorization->allows($user, DevicePermission::PROVISION);
    }

    public function manageCredentials(User $user, Device $model): bool
    {
        return $this->authorization->allows($user, DevicePermission::MANAGE_CREDENTIALS, $model->organization_id);
    }

    public function decommission(User $user, Device $model): bool
    {
        return $this->authorization->allows($user, DevicePermission::DECOMMISSION, $model->organization_id);
    }

    public function reactivate(User $user, Device $model): bool
    {
        return $this->authorization->allows($user, DevicePermission::REACTIVATE, $model->organization_id);
    }
}
