<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Authorization\Services\TenantAuthorization;
use App\Domain\Reporting\Models\ReportRun;
use App\Domain\Reporting\Permissions\ReportRunPermission;
use App\Domain\Shared\Models\User;

class ReportRunPolicy
{
    public function __construct(private TenantAuthorization $authorization) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->allows($user, ReportRunPermission::VIEW_ANY);
    }

    public function view(User $user, ReportRun $reportRun): bool
    {
        return $this->authorization->allows(
            $user,
            ReportRunPermission::VIEW,
            $reportRun->organization_id,
        );
    }

    public function create(User $user): bool
    {
        return $this->authorization->allows($user, ReportRunPermission::CREATE);
    }

    public function manageSettings(User $user): bool
    {
        return $this->authorization->allows($user, ReportRunPermission::MANAGE_SETTINGS);
    }

    public function download(User $user, ReportRun $reportRun): bool
    {
        return $this->authorization->allows(
            $user,
            ReportRunPermission::DOWNLOAD,
            $reportRun->organization_id,
        );
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
