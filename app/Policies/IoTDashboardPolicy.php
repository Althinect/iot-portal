<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\IoTDashboard\Models\IoTDashboard;
use App\Domain\Shared\Models\User;

class IoTDashboardPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, IoTDashboard $dashboard): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->organizations()->whereKey($dashboard->organization_id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, IoTDashboard $dashboard): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, IoTDashboard $dashboard): bool
    {
        return $user->isSuperAdmin();
    }
}
