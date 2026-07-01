<?php

declare(strict_types=1);

use App\Domain\DataIngestion\Listeners\BroadcastTelemetryRealtimeUpdate;
use App\Domain\Shared\Services\RuntimeSettingManager;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use App\Events\TelemetryRealtimeUpdated;
use App\Events\TelemetryReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'cache.default' => 'array',
        'ingestion.broadcast_realtime' => true,
        'ingestion.broadcast_throttle_seconds' => 5,
        'ingestion.side_effects_queue_connection' => 'redis',
        'ingestion.side_effects_queue' => 'telemetry-side-effects',
    ]);

    Cache::flush();
});

it('does not queue realtime broadcasts when disabled for the organization', function (): void {
    app(RuntimeSettingManager::class)->setGlobalOverrides([
        'ingestion.pipeline.broadcast_realtime' => false,
    ]);

    $telemetryLog = DeviceTelemetryLog::factory()->create();
    $listener = app(BroadcastTelemetryRealtimeUpdate::class);

    expect($listener->shouldQueue(new TelemetryReceived($telemetryLog)))->toBeFalse();
});

it('throttles realtime broadcasts per device channel', function (): void {
    app(RuntimeSettingManager::class)->setGlobalOverrides([
        'ingestion.pipeline.broadcast_realtime' => true,
        'ingestion.pipeline.broadcast_throttle_seconds' => 60,
    ]);

    $telemetryLog = DeviceTelemetryLog::factory()->create();
    $listener = app(BroadcastTelemetryRealtimeUpdate::class);

    expect($listener->shouldQueue(new TelemetryReceived($telemetryLog)))->toBeTrue()
        ->and($listener->shouldQueue(new TelemetryReceived($telemetryLog)))->toBeFalse()
        ->and($listener->viaConnection())->toBe('redis')
        ->and($listener->viaQueue())->toBe('telemetry-side-effects');
});

it('allows every realtime broadcast when throttle is disabled', function (): void {
    app(RuntimeSettingManager::class)->setGlobalOverrides([
        'ingestion.pipeline.broadcast_realtime' => true,
        'ingestion.pipeline.broadcast_throttle_seconds' => 0,
    ]);

    $telemetryLog = DeviceTelemetryLog::factory()->create();
    $listener = app(BroadcastTelemetryRealtimeUpdate::class);

    expect($listener->shouldQueue(new TelemetryReceived($telemetryLog)))->toBeTrue()
        ->and($listener->shouldQueue(new TelemetryReceived($telemetryLog)))->toBeTrue();
});

it('keeps the realtime broadcast channel and payload contract', function (): void {
    $telemetryLog = DeviceTelemetryLog::factory()->create();
    $event = new TelemetryRealtimeUpdated($telemetryLog->id);
    $payload = $event->broadcastWith();

    expect($event->broadcastAs())->toBe('telemetry.received')
        ->and($event->broadcastOn())->not->toBe([])
        ->and($payload['id'])->toBe($telemetryLog->id)
        ->and($payload['device_channel_id'])->toBe($telemetryLog->device_channel_id)
        ->and($payload['transformed_values'])->toBe($telemetryLog->transformed_values);
});
