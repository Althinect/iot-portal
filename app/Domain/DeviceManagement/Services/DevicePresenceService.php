<?php

declare(strict_types=1);

namespace App\Domain\DeviceManagement\Services;

use App\Domain\DeviceManagement\Models\Device;
use App\Events\DeviceConnectionChanged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class DevicePresenceService
{
    public function __construct(
        private readonly DevicePresencePolicy $presencePolicy,
    ) {}

    public function markOnline(Device $device, ?Carbon $seenAt = null): void
    {
        $resolvedSeenAt = $seenAt ?? now();
        $freshDevice = $this->refreshDevice($device);

        if ($freshDevice === null) {
            return;
        }

        $previousState = $freshDevice->connection_state;

        if (! $this->presencePolicy->shouldPersistOnlineHeartbeat($freshDevice, $resolvedSeenAt)) {
            return;
        }

        $offlineDeadlineAt = $this->presencePolicy->offlineDeadlineFor($freshDevice, $resolvedSeenAt);

        $freshDevice->updateQuietly([
            'connection_state' => 'online',
            'last_seen_at' => $resolvedSeenAt,
            'offline_deadline_at' => $offlineDeadlineAt,
        ]);

        if ($previousState !== 'online') {
            try {
                event(new DeviceConnectionChanged(
                    deviceId: $freshDevice->id,
                    deviceUuid: $freshDevice->uuid,
                    connectionState: 'online',
                    lastSeenAt: $resolvedSeenAt,
                ));
            } catch (\Throwable $e) {
                Log::channel('device_control')->warning('DeviceConnectionChanged broadcast failed (non-fatal)', [
                    'device_id' => $freshDevice->id,
                    'error' => $e->getMessage(),
                ]);
            }

            Log::channel('device_control')->info('Device came online', [
                'device_id' => $freshDevice->id,
                'device_uuid' => $freshDevice->uuid,
                'previous_state' => $previousState,
            ]);
        }
    }

    public function markOffline(Device $device, ?Carbon $seenAt = null): void
    {
        $freshDevice = $this->refreshDevice($device);

        if ($freshDevice === null) {
            return;
        }

        $previousState = $freshDevice->connection_state;

        if ($previousState === 'offline') {
            if ($freshDevice->storedOfflineDeadlineAt() !== null) {
                $freshDevice->updateQuietly([
                    'offline_deadline_at' => null,
                ]);
            }

            return;
        }

        $freshDevice->updateQuietly([
            'connection_state' => 'offline',
            'offline_deadline_at' => null,
        ]);

        $resolvedLastSeenAt = $seenAt ?? $freshDevice->lastSeenAt();

        try {
            event(new DeviceConnectionChanged(
                deviceId: $freshDevice->id,
                deviceUuid: $freshDevice->uuid,
                connectionState: 'offline',
                lastSeenAt: $resolvedLastSeenAt,
            ));
        } catch (\Throwable $e) {
            Log::channel('device_control')->warning('DeviceConnectionChanged broadcast failed (non-fatal)', [
                'device_id' => $freshDevice->id,
                'error' => $e->getMessage(),
            ]);
        }

        Log::channel('device_control')->info('Device went offline', [
            'device_id' => $freshDevice->id,
            'device_uuid' => $freshDevice->uuid,
            'previous_state' => $previousState,
        ]);
    }

    public function markOfflineByUuid(string $identifier): void
    {
        $device = $this->resolveDeviceByIdentifier($identifier);

        if ($device === null) {
            Log::channel('device_control')->warning('Presence offline message for unknown device', [
                'identifier' => $identifier,
            ]);

            return;
        }

        $this->markOffline($device);
    }

    public function markOnlineByUuid(string $identifier): void
    {
        $device = $this->resolveDeviceByIdentifier($identifier);

        if ($device === null) {
            Log::channel('device_control')->warning('Presence online message for unknown device', [
                'identifier' => $identifier,
            ]);

            return;
        }

        $this->markOnline($device);
    }

    private function resolveDeviceByIdentifier(string $identifier): ?Device
    {
        if (\Illuminate\Support\Str::isUuid($identifier)) {
            $device = Device::query()->where('uuid', $identifier)->first();

            if ($device !== null) {
                return $device;
            }
        }

        return Device::query()->where('external_id', $identifier)->first();
    }

    private function refreshDevice(Device $device): ?Device
    {
        $freshDevice = Device::query()
            ->select([
                'id',
                'uuid',
                'connection_state',
                'last_seen_at',
                'offline_deadline_at',
                'presence_timeout_seconds',
            ])
            ->whereKey($device->getKey())
            ->first();

        if ($freshDevice === null) {
            Log::channel('device_control')->warning('Presence update skipped for missing device', [
                'device_id' => $device->id,
            ]);

            return null;
        }

        return $freshDevice;
    }
}
