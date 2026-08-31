<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Authorization\Services\TenantAuthorization;
use App\Domain\Automation\Models\AutomationNotificationProfile;
use App\Domain\Automation\Permissions\AutomationNotificationProfilePermission;
use App\Domain\Shared\Models\User;

class AutomationNotificationProfilePolicy
{
    public function __construct(private TenantAuthorization $authorization) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->allows($user, AutomationNotificationProfilePermission::VIEW_ANY);
    }

    public function view(User $user, AutomationNotificationProfile $profile): bool
    {
        return $this->authorization->allows(
            $user,
            AutomationNotificationProfilePermission::VIEW,
            $profile->organization_id,
        );
    }

    public function create(User $user): bool
    {
        return $this->authorization->allows($user, AutomationNotificationProfilePermission::CREATE);
    }

    public function update(User $user, AutomationNotificationProfile $profile): bool
    {
        return $this->authorization->allows(
            $user,
            AutomationNotificationProfilePermission::UPDATE,
            $profile->organization_id,
        );
    }

    public function delete(User $user, AutomationNotificationProfile $profile): bool
    {
        return $this->authorization->allows(
            $user,
            AutomationNotificationProfilePermission::ARCHIVE,
            $profile->organization_id,
        );
    }

    public function restore(User $user, AutomationNotificationProfile $profile): bool
    {
        return $this->authorization->allows(
            $user,
            AutomationNotificationProfilePermission::ARCHIVE,
            $profile->organization_id,
        );
    }

    public function forceDelete(User $user, AutomationNotificationProfile $profile): bool
    {
        return $user->isSuperAdmin();
    }
}
