<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Carbon::setTestNow();
});

it('returns offline when the offline deadline has passed even if the stored state is online', function (): void {
    $now = Carbon::parse('2026-03-05 12:00:00');

    $device = Device::factory()->create([
        'connection_state' => 'online',
        'last_seen_at' => $now->copy()->subHours(2),
        'offline_deadline_at' => $now->copy()->subMinute(),
    ]);

    expect($device->effectiveConnectionState($now))->toBe('offline');
});

it('uses the configured fallback timeout when the device does not define one', function (): void {
    $now = Carbon::parse('2026-03-05 12:00:00');

    Carbon::setTestNow($now);
    config()->set('iot.presence.heartbeat_timeout_seconds', 600);

    $device = Device::factory()->create([
        'connection_state' => 'online',
        'presence_timeout_seconds' => null,
        'last_seen_at' => $now->copy()->subMinutes(5),
    ]);

    expect($device->presenceTimeoutSeconds())->toBe(600)
        ->and($device->resolvedOfflineDeadlineAt()?->equalTo($device->last_seen_at->copy()->addSeconds(600)))->toBeTrue()
        ->and($device->effectiveConnectionState($now))->toBe('online');
});

it('uses the device-specific timeout override when one is configured', function (): void {
    $now = Carbon::parse('2026-03-05 12:00:00');

    Carbon::setTestNow($now);
    config()->set('iot.presence.heartbeat_timeout_seconds', 300);

    $device = Device::factory()->create([
        'connection_state' => 'online',
        'presence_timeout_seconds' => 900,
        'last_seen_at' => $now->copy()->subMinutes(10),
    ]);

    expect($device->presenceTimeoutSeconds())->toBe(900)
        ->and($device->resolvedOfflineDeadlineAt()?->equalTo($device->last_seen_at->copy()->addSeconds(900)))->toBeTrue()
        ->and($device->effectiveConnectionState($now))->toBe('online')
        ->and($device->effectiveConnectionState($now->copy()->addSeconds(301)))->toBe('offline');
});

it('returns unknown when the device has never been seen and is not explicitly offline', function (): void {
    $device = Device::factory()->create([
        'connection_state' => null,
        'last_seen_at' => null,
        'offline_deadline_at' => null,
    ]);

    expect($device->effectiveConnectionState(now()))->toBe('unknown');
});

it('treats an explicit offline state as offline immediately', function (): void {
    $now = Carbon::parse('2026-03-05 12:00:00');

    $device = Device::factory()->create([
        'connection_state' => 'offline',
        'last_seen_at' => $now,
        'offline_deadline_at' => $now->copy()->addHour(),
    ]);

    expect($device->effectiveConnectionState($now))->toBe('offline');
});
