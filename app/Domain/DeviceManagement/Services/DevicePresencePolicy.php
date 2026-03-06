<?php

declare(strict_types=1);

namespace App\Domain\DeviceManagement\Services;

use App\Domain\DeviceManagement\Models\Device;
use Illuminate\Support\Carbon;

final class DevicePresencePolicy
{
    private const int DEFAULT_TIMEOUT_SECONDS = 300;

    public function timeoutFor(Device $device): int
    {
        $deviceTimeout = $device->presence_timeout_seconds;

        if (is_numeric($deviceTimeout) && (int) $deviceTimeout >= 60) {
            return (int) $deviceTimeout;
        }

        $configuredTimeout = config('iot.presence.heartbeat_timeout_seconds', self::DEFAULT_TIMEOUT_SECONDS);

        if (is_numeric($configuredTimeout) && (int) $configuredTimeout > 0) {
            return (int) $configuredTimeout;
        }

        return self::DEFAULT_TIMEOUT_SECONDS;
    }

    public function writeThrottleSeconds(): int
    {
        $configuredThrottle = config('iot.presence.write_throttle_seconds', 15);

        if (is_numeric($configuredThrottle) && (int) $configuredThrottle >= 0) {
            return (int) $configuredThrottle;
        }

        return 15;
    }

    public function offlineDeadlineFor(Device $device, Carbon $seenAt): Carbon
    {
        return $seenAt->copy()->addSeconds($this->timeoutFor($device));
    }

    public function shouldPersistOnlineHeartbeat(Device $device, Carbon $seenAt): bool
    {
        if ($device->connection_state !== 'online') {
            return true;
        }

        $lastSeenAt = $device->lastSeenAt();

        if ($lastSeenAt === null || $device->storedOfflineDeadlineAt() === null) {
            return true;
        }

        if ($seenAt->lessThanOrEqualTo($lastSeenAt)) {
            return false;
        }

        return $lastSeenAt->copy()->addSeconds($this->writeThrottleSeconds())->lessThanOrEqualTo($seenAt);
    }

    public function effectiveStateFor(Device $device, Carbon $now): string
    {
        if ($device->connection_state === 'offline') {
            return 'offline';
        }

        $lastSeenAt = $device->lastSeenAt();

        if ($lastSeenAt === null) {
            return 'unknown';
        }

        $offlineDeadlineAt = $device->storedOfflineDeadlineAt() ?? $this->offlineDeadlineFor($device, $lastSeenAt);

        return $offlineDeadlineAt->lessThanOrEqualTo($now) ? 'offline' : 'online';
    }
}
