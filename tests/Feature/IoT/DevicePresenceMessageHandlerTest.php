<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Services\DevicePresenceMessageHandler;
use App\Events\DeviceConnectionChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('cache.default', 'array');
    $this->handler = app(DevicePresenceMessageHandler::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('builds the wildcard presence subscription subject from configured fragments', function (): void {
    expect($this->handler->subscriptionSubject('devices', 'presence'))->toBe('devices.*.presence')
        ->and($this->handler->subscriptionSubject('tenant/devices', 'presence/state'))->toBe('tenant.devices.*.presence.state');
});

it('marks a device online when a matching presence subject uses its external id', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-03-07 12:00:00'));
    config()->set('iot.presence.heartbeat_timeout_seconds', 300);

    $device = Device::factory()->create([
        'external_id' => 'rgb-led-01',
        'connection_state' => 'offline',
        'last_seen_at' => null,
        'offline_deadline_at' => null,
    ]);

    Event::fake([DeviceConnectionChanged::class]);

    $handled = $this->handler->handle(
        subject: 'devices.rgb-led-01.presence',
        body: ' online ',
        prefix: 'devices',
        suffix: 'presence',
    );

    $device->refresh();

    expect($handled)->toBeTrue()
        ->and($device->connection_state)->toBe('online')
        ->and($device->last_seen_at?->equalTo(now()))->toBeTrue()
        ->and($device->offline_deadline_at?->equalTo(now()->addSeconds(300)))->toBeTrue();

    Event::assertDispatched(DeviceConnectionChanged::class, function (DeviceConnectionChanged $event) use ($device): bool {
        return $event->deviceId === $device->id
            && $event->deviceUuid === $device->uuid
            && $event->connectionState === 'online';
    });
});

it('marks a device online when a matching presence subject carries a json payload', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-03-07 13:00:00'));
    config()->set('iot.presence.heartbeat_timeout_seconds', 300);

    $device = Device::factory()->create([
        'external_id' => 'rgb-led-01',
        'connection_state' => 'offline',
        'last_seen_at' => null,
        'offline_deadline_at' => null,
    ]);

    Event::fake([DeviceConnectionChanged::class]);

    $handled = $this->handler->handle(
        subject: 'devices.rgb-led-01.presence',
        body: json_encode([
            'status' => 'online',
            '_meta' => [
                'source' => 'node-red-imoni',
            ],
        ], JSON_THROW_ON_ERROR),
        prefix: 'devices',
        suffix: 'presence',
    );

    $device->refresh();

    expect($handled)->toBeTrue()
        ->and($device->connection_state)->toBe('online')
        ->and($device->last_seen_at?->equalTo(now()))->toBeTrue();

    Event::assertDispatched(DeviceConnectionChanged::class, 1);
});

it('marks a device offline when a matching presence subject uses its uuid', function (): void {
    $lastSeenAt = Carbon::parse('2026-03-07 11:58:00');
    Carbon::setTestNow($lastSeenAt->copy()->addMinute());

    $device = Device::factory()->create([
        'connection_state' => 'online',
        'last_seen_at' => $lastSeenAt,
        'offline_deadline_at' => $lastSeenAt->copy()->addMinutes(5),
    ]);

    Event::fake([DeviceConnectionChanged::class]);

    $handled = $this->handler->handle(
        subject: "devices.{$device->uuid}.presence",
        body: 'offline',
        prefix: 'devices',
        suffix: 'presence',
    );

    $device->refresh();

    expect($handled)->toBeTrue()
        ->and($device->connection_state)->toBe('offline')
        ->and($device->last_seen_at?->equalTo($lastSeenAt))->toBeTrue()
        ->and($device->offline_deadline_at)->toBeNull();

    Event::assertDispatched(DeviceConnectionChanged::class, function (DeviceConnectionChanged $event) use ($device): bool {
        return $event->deviceId === $device->id
            && $event->deviceUuid === $device->uuid
            && $event->connectionState === 'offline';
    });
});

it('ignores subjects that do not match the configured presence pattern', function (): void {
    $device = Device::factory()->create([
        'external_id' => 'rgb-led-01',
        'connection_state' => 'offline',
    ]);

    Event::fake([DeviceConnectionChanged::class]);

    $handled = $this->handler->handle(
        subject: 'devices.rgb-led-01.state',
        body: 'online',
        prefix: 'devices',
        suffix: 'presence',
    );

    $device->refresh();

    expect($handled)->toBeFalse()
        ->and($device->connection_state)->toBe('offline');

    Event::assertNotDispatched(DeviceConnectionChanged::class);
});

it('ignores unknown presence payloads on matching subjects', function (): void {
    $device = Device::factory()->create([
        'external_id' => 'rgb-led-01',
        'connection_state' => 'offline',
    ]);

    Event::fake([DeviceConnectionChanged::class]);

    $handled = $this->handler->handle(
        subject: 'devices.rgb-led-01.presence',
        body: 'sleeping',
        prefix: 'devices',
        suffix: 'presence',
    );

    $device->refresh();

    expect($handled)->toBeFalse()
        ->and($device->connection_state)->toBe('offline');

    Event::assertNotDispatched(DeviceConnectionChanged::class);
});
