<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Authorization\Services\TenantAuthorization;
use App\Domain\IoTDashboard\Models\IoTDashboard;
use App\Domain\IoTDashboard\Permissions\IoTDashboardPermission;
use App\Domain\Shared\Models\User;

class IoTDashboardPolicy
{
    public function __construct(private TenantAuthorization $authorization) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->allows($user, IoTDashboardPermission::VIEW_ANY);
    }

    public function view(User $user, IoTDashboard $dashboard): bool
    {
        return $this->authorization->allows(
            $user,
            IoTDashboardPermission::VIEW,
            $dashboard->organization_id,
        );
    }

    public function create(User $user): bool
    {
        return $this->authorization->allows($user, IoTDashboardPermission::CREATE);
    }

    public function update(User $user, IoTDashboard $dashboard): bool
    {
        return $this->authorization->allows(
            $user,
            IoTDashboardPermission::UPDATE,
            $dashboard->organization_id,
        );
    }

    public function delete(User $user, IoTDashboard $dashboard): bool
    {
        return $this->authorization->allows(
            $user,
            IoTDashboardPermission::ARCHIVE,
            $dashboard->organization_id,
        );
    }

    public function restore(User $user, IoTDashboard $dashboard): bool
    {
        return $this->authorization->allows(
            $user,
            IoTDashboardPermission::RESTORE,
            $dashboard->organization_id,
        );
    }

    public function forceDelete(User $user, IoTDashboard $dashboard): bool
    {
        return $user->isSuperAdmin();
    }
}
