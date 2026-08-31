<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Authorization\Services\TenantAuthorization;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Permissions\DeviceProfileVersionPermission;
use App\Domain\Shared\Models\User;

class DeviceProfileVersionPolicy
{
    public function __construct(private TenantAuthorization $authorization) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->allows($user, DeviceProfileVersionPermission::VIEW_ANY);
    }

    public function view(User $user, DeviceProfileVersion $deviceProfileVersion): bool
    {
        return $this->allowsForVersion($user, DeviceProfileVersionPermission::VIEW, $deviceProfileVersion, true);
    }

    public function create(User $user): bool
    {
        return $this->authorization->allows($user, DeviceProfileVersionPermission::CREATE);
    }

    public function update(User $user, DeviceProfileVersion $deviceProfileVersion): bool
    {
        return $deviceProfileVersion->isDraft()
            && $this->allowsForVersion(
                $user,
                DeviceProfileVersionPermission::UPDATE,
                $deviceProfileVersion,
            );
    }

    public function delete(User $user, DeviceProfileVersion $deviceProfileVersion): bool
    {
        return $deviceProfileVersion->isDraft()
            && $this->allowsForVersion(
                $user,
                DeviceProfileVersionPermission::DELETE,
                $deviceProfileVersion,
            );
    }

    public function restore(User $user, DeviceProfileVersion $deviceProfileVersion): bool
    {
        return $user->isSuperAdmin();
    }

    public function forceDelete(User $user, DeviceProfileVersion $deviceProfileVersion): bool
    {
        return $user->isSuperAdmin();
    }

    public function activate(User $user, DeviceProfileVersion $deviceProfileVersion): bool
    {
        return $deviceProfileVersion->isDraft()
            && $this->allowsForVersion(
                $user,
                DeviceProfileVersionPermission::ACTIVATE,
                $deviceProfileVersion,
            );
    }

    private function allowsForVersion(
        User $user,
        DeviceProfileVersionPermission $permission,
        DeviceProfileVersion $deviceProfileVersion,
        bool $allowGlobal = false,
    ): bool {
        $deviceProfileVersion->loadMissing('profile');
        $organizationId = $deviceProfileVersion->profile?->organization_id;

        if ($allowGlobal) {
            return $this->authorization->allowsGlobalOrTenantRecord($user, $permission, $organizationId);
        }

        return $organizationId !== null
            && $this->authorization->allows($user, $permission, $organizationId);
    }
}
