<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Reporting\Models\ReportRun;
use App\Domain\Shared\Models\User;

class ReportRunPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->organizations()->exists();
    }

    public function view(User $user, ReportRun $reportRun): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->organizations()->whereKey($reportRun->organization_id)->exists();
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, ReportRun $reportRun): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, ReportRun $reportRun): bool
    {
        return $user->isSuperAdmin();
    }

    public function restore(User $user, ReportRun $reportRun): bool
    {
        return $user->isSuperAdmin();
    }

    public function forceDelete(User $user, ReportRun $reportRun): bool
    {
        return $user->isSuperAdmin();
    }
}
