<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\Shared\Models\User;

class IoTDashboardDeviceTopicChannel
{
    public function join(User $user, string $deviceUuid, int|string $topicId): bool
    {
        $resolvedDeviceUuid = trim($deviceUuid);
        $resolvedTopicId = is_numeric($topicId) ? (int) $topicId : 0;

        if ($resolvedDeviceUuid === '' || $resolvedTopicId < 1) {
            return false;
        }

        $deviceQuery = Device::query()->where('uuid', $resolvedDeviceUuid);

        if ($user->isSuperAdmin()) {
            return $deviceQuery->exists();
        }

        return $deviceQuery
            ->whereIn('organization_id', $user->organizations()->select('organizations.id'))
            ->exists();
    }
}
