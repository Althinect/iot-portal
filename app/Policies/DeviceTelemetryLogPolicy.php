<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Authorization\Services\TenantAuthorization;
use App\Domain\Shared\Models\User;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use App\Domain\Telemetry\Permissions\DeviceTelemetryLogPermission;

class DeviceTelemetryLogPolicy
{
    public function __construct(private TenantAuthorization $authorization) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->allows($user, DeviceTelemetryLogPermission::VIEW_ANY);
    }

    public function view(User $user, DeviceTelemetryLog $deviceTelemetryLog): bool
    {
        $deviceTelemetryLog->loadMissing('device');

        return $deviceTelemetryLog->device !== null
            && $this->authorization->allows(
                $user,
                DeviceTelemetryLogPermission::VIEW,
                $deviceTelemetryLog->device->organization_id,
            );
    }

    public function create(User $user): bool
    {
        return $this->authorization->allows($user, DeviceTelemetryLogPermission::CREATE);
    }

    public function update(User $user, DeviceTelemetryLog $deviceTelemetryLog): bool
    {
        return $deviceTelemetryLog->device !== null
            && $this->authorization->allows(
                $user,
                DeviceTelemetryLogPermission::UPDATE,
                $deviceTelemetryLog->device->organization_id,
            );
    }

    public function delete(User $user, DeviceTelemetryLog $deviceTelemetryLog): bool
    {
        return $deviceTelemetryLog->device !== null
            && $this->authorization->allows(
                $user,
                DeviceTelemetryLogPermission::DELETE,
                $deviceTelemetryLog->device->organization_id,
            );
    }

    public function restore(User $user, DeviceTelemetryLog $deviceTelemetryLog): bool
    {
        return $user->isSuperAdmin();
    }

    public function forceDelete(User $user, DeviceTelemetryLog $deviceTelemetryLog): bool
    {
        return $user->isSuperAdmin();
    }
}
