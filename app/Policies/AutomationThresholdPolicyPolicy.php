<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Authorization\Services\TenantAuthorization;
use App\Domain\Automation\Models\AutomationThresholdPolicy;
use App\Domain\Automation\Permissions\AutomationThresholdPolicyPermission;
use App\Domain\Shared\Models\User;

class AutomationThresholdPolicyPolicy
{
    public function __construct(private TenantAuthorization $authorization) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->allows($user, AutomationThresholdPolicyPermission::VIEW_ANY);
    }

    public function view(User $user, AutomationThresholdPolicy $policy): bool
    {
        return $this->authorization->allows(
            $user,
            AutomationThresholdPolicyPermission::VIEW,
            $policy->organization_id,
        );
    }

    public function create(User $user): bool
    {
        return $this->authorization->allows($user, AutomationThresholdPolicyPermission::CREATE);
    }

    public function update(User $user, AutomationThresholdPolicy $policy): bool
    {
        return $this->authorization->allows(
            $user,
            AutomationThresholdPolicyPermission::UPDATE,
            $policy->organization_id,
        );
    }

    public function delete(User $user, AutomationThresholdPolicy $policy): bool
    {
        return $this->authorization->allows(
            $user,
            AutomationThresholdPolicyPermission::ARCHIVE,
            $policy->organization_id,
        );
    }

    public function restore(User $user, AutomationThresholdPolicy $policy): bool
    {
        return $this->authorization->allows(
            $user,
            AutomationThresholdPolicyPermission::ARCHIVE,
            $policy->organization_id,
        );
    }

    public function forceDelete(User $user, AutomationThresholdPolicy $policy): bool
    {
        return $user->isSuperAdmin();
    }
}
