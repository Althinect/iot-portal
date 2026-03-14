<?php

declare(strict_types=1);

use App\Domain\Shared\Services\RuntimeSettingManager;
use App\Events\TelemetryIncoming;
use Illuminate\Broadcasting\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('does not broadcast raw telemetry by default', function (): void {
    $event = new TelemetryIncoming(
        topic: 'devices/fan/external-1/status',
        deviceUuid: 'uuid-1',
        deviceExternalId: 'external-1',
        payload: ['fan_speed' => 2],
    );

    expect($event->broadcastOn())->toBe([]);
});

it('broadcasts raw telemetry when explicitly enabled', function (): void {
    app(RuntimeSettingManager::class)->setGlobalOverrides([
        'iot.diagnostics.raw_telemetry_stream' => true,
    ]);

    $event = new TelemetryIncoming(
        topic: 'devices/fan/external-1/status',
        deviceUuid: 'uuid-1',
        deviceExternalId: 'external-1',
        payload: ['fan_speed' => 2],
    );

    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(1)
        ->and($channels[0])->toBeInstanceOf(Channel::class)
        ->and($channels[0]->name)->toBe('telemetry');
});
