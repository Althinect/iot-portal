<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Services\DevicePresenceService;
use App\Events\DeviceConnectionChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('cache.default', 'array');
    $this->service = app(DevicePresenceService::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('marks a device online and broadcasts when transitioning from offline', function (): void {
    Event::fake([DeviceConnectionChanged::class]);
    Carbon::setTestNow(Carbon::parse('2026-03-05 12:00:00'));
    config()->set('iot.presence.heartbeat_timeout_seconds', 300);

    $device = Device::factory()->create([
        'connection_state' => 'offline',
        'last_seen_at' => null,
        'offline_deadline_at' => null,
    ]);

    $this->service->markOnline($device);

    $device->refresh();
    expect($device->connection_state)->toBe('online')
        ->and($device->last_seen_at)->not->toBeNull()
        ->and($device->offline_deadline_at?->equalTo($device->last_seen_at->copy()->addSeconds(300)))->toBeTrue();

    Event::assertDispatched(DeviceConnectionChanged::class, function (DeviceConnectionChanged $event) use ($device): bool {
        return $event->deviceId === $device->id && $event->connectionState === 'online';
    });
});

it('updates last_seen_at and uses the device-specific timeout without broadcasting when already online', function (): void {
    Event::fake([DeviceConnectionChanged::class]);
    $seenAt = Carbon::parse('2026-03-05 12:05:00');
    config()->set('iot.presence.write_throttle_seconds', 15);

    $device = Device::factory()->create([
        'connection_state' => 'online',
        'presence_timeout_seconds' => 900,
        'last_seen_at' => $seenAt->copy()->subMinutes(5),
    ]);

    $this->service->markOnline($device, $seenAt);

    $device->refresh();
    expect($device->connection_state)->toBe('online')
        ->and($device->last_seen_at?->equalTo($seenAt))->toBeTrue()
        ->and($device->offline_deadline_at?->equalTo($seenAt->copy()->addSeconds(900)))->toBeTrue();

    Event::assertNotDispatched(DeviceConnectionChanged::class);
});

it('skips presence writes when an online heartbeat arrives within the write throttle window', function (): void {
    Event::fake([DeviceConnectionChanged::class]);
    config()->set('iot.presence.write_throttle_seconds', 30);

    $lastSeenAt = Carbon::parse('2026-03-05 12:00:00');
    $offlineDeadlineAt = $lastSeenAt->copy()->addMinutes(5);
    $seenAt = $lastSeenAt->copy()->addSeconds(10);

    $device = Device::factory()->create([
        'connection_state' => 'online',
        'last_seen_at' => $lastSeenAt,
        'offline_deadline_at' => $offlineDeadlineAt,
    ]);

    $this->service->markOnline($device, $seenAt);

    $device->refresh();

    expect($device->last_seen_at?->equalTo($lastSeenAt))->toBeTrue()
        ->and($device->offline_deadline_at?->equalTo($offlineDeadlineAt))->toBeTrue();

    Event::assertNotDispatched(DeviceConnectionChanged::class);
});

it('broadcasts when a stale online model is offline in the database', function (): void {
    Event::fake([DeviceConnectionChanged::class]);

    $device = Device::factory()->create([
        'connection_state' => 'online',
        'last_seen_at' => now()->subMinutes(5),
    ]);

    $staleDevice = Device::find($device->id);

    Device::query()
        ->whereKey($device->id)
        ->update(['connection_state' => 'offline', 'last_seen_at' => null]);

    $this->service->markOnline($staleDevice);

    $device->refresh();

    expect($device->connection_state)->toBe('online')
        ->and($device->last_seen_at)->not->toBeNull();

    Event::assertDispatched(DeviceConnectionChanged::class, function (DeviceConnectionChanged $event) use ($device): bool {
        return $event->deviceId === $device->id && $event->connectionState === 'online';
    });
});

it('marks a device offline and broadcasts when transitioning from online', function (): void {
    Event::fake([DeviceConnectionChanged::class]);
    $lastSeenAt = Carbon::parse('2026-03-05 11:58:00');

    $device = Device::factory()->create([
        'connection_state' => 'online',
        'last_seen_at' => $lastSeenAt,
        'offline_deadline_at' => $lastSeenAt->copy()->addMinutes(5),
    ]);

    $this->service->markOffline($device);

    $device->refresh();
    expect($device->connection_state)->toBe('offline')
        ->and($device->last_seen_at?->equalTo($lastSeenAt))->toBeTrue()
        ->and($device->offline_deadline_at)->toBeNull();

    Event::assertDispatched(DeviceConnectionChanged::class, function (DeviceConnectionChanged $event) use ($device): bool {
        return $event->deviceId === $device->id && $event->connectionState === 'offline';
    });
});

it('clears the offline deadline without broadcasting when marking an already offline device as offline', function (): void {
    Event::fake([DeviceConnectionChanged::class]);

    $device = Device::factory()->create([
        'connection_state' => 'offline',
        'last_seen_at' => now()->subHours(1),
    ]);

    Device::query()
        ->whereKey($device->id)
        ->update([
            'offline_deadline_at' => now()->addMinutes(2),
        ]);

    $this->service->markOffline($device);

    $device->refresh();

    expect($device->offline_deadline_at)->toBeNull();

    Event::assertNotDispatched(DeviceConnectionChanged::class);
});

it('marks an unknown state device online and broadcasts', function (): void {
    Event::fake([DeviceConnectionChanged::class]);

    $device = Device::factory()->create([
        'connection_state' => 'unknown',
        'last_seen_at' => null,
    ]);

    $this->service->markOnline($device);

    $device->refresh();
    expect($device->connection_state)->toBe('online');

    Event::assertDispatched(DeviceConnectionChanged::class, 1);
});

it('resolves a device by UUID and marks it online', function (): void {
    Event::fake([DeviceConnectionChanged::class]);

    $device = Device::factory()->create([
        'connection_state' => 'offline',
    ]);

    $this->service->markOnlineByUuid($device->uuid);

    $device->refresh();
    expect($device->connection_state)->toBe('online');

    Event::assertDispatched(DeviceConnectionChanged::class, 1);
});

it('resolves a device by UUID and marks it offline', function (): void {
    Event::fake([DeviceConnectionChanged::class]);

    $device = Device::factory()->create([
        'connection_state' => 'online',
    ]);

    $this->service->markOfflineByUuid($device->uuid);

    $device->refresh();
    expect($device->connection_state)->toBe('offline');

    Event::assertDispatched(DeviceConnectionChanged::class, 1);
});

it('handles unknown UUID gracefully without exceptions', function (): void {
    Event::fake([DeviceConnectionChanged::class]);

    $this->service->markOnlineByUuid('non-existent-uuid');
    $this->service->markOfflineByUuid('non-existent-uuid');

    Event::assertNotDispatched(DeviceConnectionChanged::class);
});

it('resolves a device by external_id and marks it online', function (): void {
    Event::fake([DeviceConnectionChanged::class]);

    $device = Device::factory()->create([
        'external_id' => 'rgb-led-01',
        'connection_state' => 'offline',
    ]);

    $this->service->markOnlineByUuid('rgb-led-01');

    $device->refresh();
    expect($device->connection_state)->toBe('online');

    Event::assertDispatched(DeviceConnectionChanged::class, 1);
});

it('resolves a device by external_id and marks it offline', function (): void {
    Event::fake([DeviceConnectionChanged::class]);

    $device = Device::factory()->create([
        'external_id' => 'rgb-led-01',
        'connection_state' => 'online',
    ]);

    $this->service->markOfflineByUuid('rgb-led-01');

    $device->refresh();
    expect($device->connection_state)->toBe('offline');

    Event::assertDispatched(DeviceConnectionChanged::class, 1);
});

it('prefers UUID over external_id when both could match', function (): void {
    Event::fake([DeviceConnectionChanged::class]);

    $sharedUuid = '00000000-0000-4000-8000-000000000001';

    $deviceByUuid = Device::factory()->create([
        'uuid' => $sharedUuid,
        'external_id' => 'other-id',
        'connection_state' => 'offline',
    ]);

    $deviceByExternal = Device::factory()->create([
        'external_id' => $sharedUuid,
        'connection_state' => 'offline',
    ]);

    $this->service->markOnlineByUuid($sharedUuid);

    $deviceByUuid->refresh();
    $deviceByExternal->refresh();

    expect($deviceByUuid->connection_state)->toBe('online')
        ->and($deviceByExternal->connection_state)->toBe('offline');
});
