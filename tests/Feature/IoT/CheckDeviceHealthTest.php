<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Models\Device;
use App\Events\DeviceConnectionChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Carbon::setTestNow();
});

it('marks devices offline when their offline deadline has passed and broadcasts events', function (): void {
    Event::fake([DeviceConnectionChanged::class]);
    $now = Carbon::parse('2026-03-05 12:00:00');

    Carbon::setTestNow($now);

    $staleDevice = Device::factory()->create([
        'connection_state' => 'online',
        'last_seen_at' => $now->copy()->subMinutes(10),
        'offline_deadline_at' => $now->copy()->subMinute(),
    ]);

    $recentDevice = Device::factory()->create([
        'connection_state' => 'online',
        'last_seen_at' => $now->copy()->subSeconds(30),
        'offline_deadline_at' => $now->copy()->addMinutes(4),
    ]);

    $alreadyOffline = Device::factory()->create([
        'connection_state' => 'offline',
        'last_seen_at' => $now->copy()->subHours(1),
    ]);

    $this->artisan('iot:check-device-health', ['--seconds' => 300])
        ->assertExitCode(0);

    $staleDevice->refresh();
    $recentDevice->refresh();
    $alreadyOffline->refresh();

    expect($staleDevice->connection_state)->toBe('offline')
        ->and($recentDevice->connection_state)->toBe('online')
        ->and($alreadyOffline->connection_state)->toBe('offline');

    Event::assertDispatched(DeviceConnectionChanged::class, 1);

    Event::assertDispatched(DeviceConnectionChanged::class, function (DeviceConnectionChanged $event) use ($staleDevice): bool {
        return $event->deviceId === $staleDevice->id && $event->connectionState === 'offline';
    });
});

it('marks devices with null last_seen_at as offline', function (): void {
    Event::fake([DeviceConnectionChanged::class]);
    Carbon::setTestNow(Carbon::parse('2026-03-05 12:00:00'));

    $device = Device::factory()->create([
        'connection_state' => 'online',
        'last_seen_at' => null,
        'offline_deadline_at' => null,
    ]);

    $this->artisan('iot:check-device-health', ['--seconds' => 300])
        ->assertExitCode(0);

    $device->refresh();
    expect($device->connection_state)->toBe('offline');

    Event::assertDispatched(DeviceConnectionChanged::class, 1);
});

it('does not mark recently seen devices as offline', function (): void {
    Event::fake([DeviceConnectionChanged::class]);
    $now = Carbon::parse('2026-03-05 12:00:00');

    Carbon::setTestNow($now);

    $device = Device::factory()->create([
        'connection_state' => 'online',
        'last_seen_at' => $now,
        'offline_deadline_at' => $now->copy()->addMinutes(5),
    ]);

    $this->artisan('iot:check-device-health', ['--seconds' => 300])
        ->assertExitCode(0);

    $device->refresh();
    expect($device->connection_state)->toBe('online');

    Event::assertNotDispatched(DeviceConnectionChanged::class);
});

it('applies the command seconds override to devices with persisted offline deadlines', function (): void {
    Event::fake([DeviceConnectionChanged::class]);
    $now = Carbon::parse('2026-03-05 12:00:00');

    Carbon::setTestNow($now);

    $device = Device::factory()->create([
        'connection_state' => 'online',
        'last_seen_at' => $now->copy()->subSeconds(90),
    ]);

    $this->artisan('iot:check-device-health', ['--seconds' => 60])
        ->assertExitCode(0);

    $device->refresh();

    expect($device->connection_state)->toBe('offline');

    Event::assertDispatched(DeviceConnectionChanged::class, 1);
});

it('allows a longer command seconds override to defer offline checks for persisted deadlines', function (): void {
    Event::fake([DeviceConnectionChanged::class]);
    $now = Carbon::parse('2026-03-05 12:00:00');

    Carbon::setTestNow($now);

    $device = Device::factory()->create([
        'connection_state' => 'online',
        'last_seen_at' => $now->copy()->subMinutes(6),
    ]);

    $this->artisan('iot:check-device-health', ['--seconds' => 600])
        ->assertExitCode(0);

    $device->refresh();

    expect($device->connection_state)->toBe('online');

    Event::assertNotDispatched(DeviceConnectionChanged::class);
});

it('marks legacy online devices with no offline deadline as offline when they exceed the fallback timeout', function (): void {
    Event::fake([DeviceConnectionChanged::class]);
    $now = Carbon::parse('2026-03-05 12:00:00');

    Carbon::setTestNow($now);

    $device = Device::factory()->create([
        'connection_state' => 'online',
        'last_seen_at' => $now->copy()->subMinutes(10),
    ]);

    Device::query()
        ->whereKey($device->id)
        ->update([
            'offline_deadline_at' => null,
        ]);

    $this->artisan('iot:check-device-health', ['--seconds' => 300])
        ->assertExitCode(0);

    $device->refresh();

    expect($device->connection_state)->toBe('offline')
        ->and($device->offline_deadline_at)->toBeNull();

    Event::assertDispatched(DeviceConnectionChanged::class, 1);
});
