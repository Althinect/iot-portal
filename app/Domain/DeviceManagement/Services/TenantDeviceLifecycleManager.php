<?php

declare(strict_types=1);

namespace App\Domain\DeviceManagement\Services;

use App\Domain\Authorization\Services\TenantAuthorization;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Permissions\DevicePermission;
use App\Domain\Shared\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class TenantDeviceLifecycleManager
{
    public function __construct(
        private TenantAuthorization $authorization,
        private DeviceCertificateIssuer $certificateIssuer,
    ) {}

    public function decommission(Device $device, User $user): void
    {
        $this->authorize($user, $device, DevicePermission::DECOMMISSION);

        DB::transaction(function () use ($device): void {
            $this->certificateIssuer->revokeActiveForDevice($device, 'device_decommissioned');
            $device->forceFill(['is_active' => false])->save();
            $device->delete();
        });
    }

    public function reactivate(Device $device, User $user): void
    {
        $this->authorize($user, $device, DevicePermission::REACTIVATE);

        DB::transaction(function () use ($device): void {
            $device->restore();
            $device->forceFill(['is_active' => true])->save();
        });
    }

    private function authorize(User $user, Device $device, DevicePermission $permission): void
    {
        if (! $this->authorization->allows($user, $permission, $device->organization_id)) {
            throw new AuthorizationException;
        }
    }
}
