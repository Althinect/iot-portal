<?php

declare(strict_types=1);

namespace App\Domain\DeviceManagement\Services;

use App\Domain\DeviceManagement\Models\Device;
use App\Events\DeviceConnectionChanged;
use Illuminate\Cache\CacheManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class DevicePresenceService
{
    public function __construct(
        private readonly DevicePresencePolicy $presencePolicy,
        private readonly CacheManager $cacheManager,
    ) {}

    public function markOnline(Device $device, ?Carbon $seenAt = null): void
    {
        $resolvedSeenAt = $seenAt ?? now();

        if ($this->withinWriteThrottleWindow($device, $resolvedSeenAt)) {
            return;
        }

        $offlineDeadlineAt = $this->presencePolicy->offlineDeadlineFor($device, $resolvedSeenAt);
        $updatedAt = now();
        $transitionedToOnline = Device::query()
            ->whereKey($device->getKey())
            ->where(function ($query): void {
                $query
                    ->where('connection_state', '!=', 'online')
                    ->orWhereNull('connection_state');
            })
            ->update([
                'connection_state' => 'online',
                'last_seen_at' => $resolvedSeenAt,
                'offline_deadline_at' => $offlineDeadlineAt,
                'updated_at' => $updatedAt,
            ]) > 0;

        if (! $transitionedToOnline) {
            Device::query()
                ->whereKey($device->getKey())
                ->update([
                    'last_seen_at' => $resolvedSeenAt,
                    'offline_deadline_at' => $offlineDeadlineAt,
                    'updated_at' => $updatedAt,
                ]);
        }

        $previousState = $transitionedToOnline ? $device->connection_state : 'online';

        $device->forceFill([
            'connection_state' => 'online',
            'last_seen_at' => $resolvedSeenAt,
            'offline_deadline_at' => $offlineDeadlineAt,
            'updated_at' => $updatedAt,
        ]);
        $this->rememberLastPresenceWrite($device, $resolvedSeenAt);

        if ($transitionedToOnline) {
            try {
                event(new DeviceConnectionChanged(
                    deviceId: $device->id,
                    deviceUuid: $device->uuid,
                    connectionState: 'online',
                    lastSeenAt: $resolvedSeenAt,
                ));
            } catch (\Throwable $e) {
                Log::channel('device_control')->warning('DeviceConnectionChanged broadcast failed (non-fatal)', [
                    'device_id' => $device->id,
                    'error' => $e->getMessage(),
                ]);
            }

            Log::channel('device_control')->debug('Device came online', [
                'device_id' => $device->id,
                'device_uuid' => $device->uuid,
                'previous_state' => $previousState,
            ]);
        }
    }

    public function markOffline(Device $device, ?Carbon $seenAt = null): void
    {
        $updatedAt = now();
        $transitionedToOffline = Device::query()
            ->whereKey($device->getKey())
            ->where(function ($query): void {
                $query
                    ->where('connection_state', '!=', 'offline')
                    ->orWhereNull('connection_state');
            })
            ->update([
                'connection_state' => 'offline',
                'offline_deadline_at' => null,
                'updated_at' => $updatedAt,
            ]) > 0;

        if (! $transitionedToOffline) {
            Device::query()
                ->whereKey($device->getKey())
                ->whereNotNull('offline_deadline_at')
                ->update([
                    'offline_deadline_at' => null,
                    'updated_at' => $updatedAt,
                ]);
        }

        $previousState = $transitionedToOffline ? $device->connection_state : 'offline';

        $device->forceFill([
            'connection_state' => 'offline',
            'offline_deadline_at' => null,
            'updated_at' => $updatedAt,
        ]);
        $this->forgetLastPresenceWrite($device);

        $resolvedLastSeenAt = $seenAt ?? $device->lastSeenAt();

        if (! $transitionedToOffline) {
            return;
        }

        try {
            event(new DeviceConnectionChanged(
                deviceId: $device->id,
                deviceUuid: $device->uuid,
                connectionState: 'offline',
                lastSeenAt: $resolvedLastSeenAt,
            ));
        } catch (\Throwable $e) {
            Log::channel('device_control')->warning('DeviceConnectionChanged broadcast failed (non-fatal)', [
                'device_id' => $device->id,
                'error' => $e->getMessage(),
            ]);
        }

        Log::channel('device_control')->debug('Device went offline', [
            'device_id' => $device->id,
            'device_uuid' => $device->uuid,
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

    private function withinWriteThrottleWindow(Device $device, Carbon $seenAt): bool
    {
        if (! $this->presencePolicy->shouldPersistOnlineHeartbeat($device, $seenAt)) {
            return true;
        }

        $writeThrottleSeconds = $this->presencePolicy->writeThrottleSeconds();

        if ($writeThrottleSeconds === 0) {
            return false;
        }

        $lastWrite = $this->cacheManager->store()->get($this->presenceCacheKey($device));

        if (! is_string($lastWrite) || trim($lastWrite) === '') {
            return false;
        }

        try {
            $lastWriteAt = Carbon::parse($lastWrite);
        } catch (\Throwable) {
            return false;
        }

        if ($seenAt->lessThanOrEqualTo($lastWriteAt)) {
            return true;
        }

        return $lastWriteAt->copy()->addSeconds($writeThrottleSeconds)->greaterThan($seenAt);
    }

    private function rememberLastPresenceWrite(Device $device, Carbon $seenAt): void
    {
        $ttlSeconds = max($device->presenceTimeoutSeconds(), $this->presencePolicy->writeThrottleSeconds(), 60);

        $this->cacheManager->store()->put(
            $this->presenceCacheKey($device),
            $seenAt->toIso8601String(),
            now()->addSeconds($ttlSeconds),
        );
    }

    private function forgetLastPresenceWrite(Device $device): void
    {
        $this->cacheManager->store()->forget($this->presenceCacheKey($device));
    }

    private function presenceCacheKey(Device $device): string
    {
        $deviceKey = $device->getKey();

        if (is_int($deviceKey) || is_string($deviceKey)) {
            return 'iot:presence:last-write:'.(string) $deviceKey;
        }

        return 'iot:presence:last-write:'.spl_object_hash($device);
    }
}
