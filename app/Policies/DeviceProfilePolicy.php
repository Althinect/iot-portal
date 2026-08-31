<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Authorization\Services\TenantAuthorization;
use App\Domain\DeviceProfile\Models\DeviceProfile;
use App\Domain\DeviceProfile\Permissions\DeviceProfilePermission;
use App\Domain\Shared\Models\User;

class DeviceProfilePolicy
{
    public function __construct(private TenantAuthorization $authorization) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->allows($user, DeviceProfilePermission::VIEW_ANY);
    }

    public function view(User $user, DeviceProfile $deviceProfile): bool
    {
        return $this->authorization->allowsGlobalOrTenantRecord(
            $user,
            DeviceProfilePermission::VIEW,
            $deviceProfile->organization_id,
        );
    }

    public function create(User $user): bool
    {
        return $this->authorization->allows($user, DeviceProfilePermission::CREATE);
    }

    public function update(User $user, DeviceProfile $deviceProfile): bool
    {
        return $deviceProfile->organization_id !== null
            && $this->authorization->allows(
                $user,
                DeviceProfilePermission::UPDATE,
                $deviceProfile->organization_id,
            );
    }

    public function delete(User $user, DeviceProfile $deviceProfile): bool
    {
        return $deviceProfile->organization_id !== null
            && $this->authorization->allows(
                $user,
                DeviceProfilePermission::DELETE,
                $deviceProfile->organization_id,
            );
    }

    public function restore(User $user, DeviceProfile $deviceProfile): bool
    {
        return $deviceProfile->organization_id !== null
            && $this->authorization->allows(
                $user,
                DeviceProfilePermission::RESTORE,
                $deviceProfile->organization_id,
            );
    }

    public function forceDelete(User $user, DeviceProfile $deviceProfile): bool
    {
        return $user->isSuperAdmin();
    }
}
