<?php

declare(strict_types=1);

use App\Console\Commands\Ingestion\ConsumeTelemetryIngestionEvents;
use App\Domain\DataIngestion\Models\IngestionMessage;
use App\Domain\Shared\Services\RuntimeSettingRegistry;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use App\Events\TelemetryIncoming;
use App\Events\TelemetryReceived;
use Basis\Nats\Message\Payload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

it('allows go as an ingestion pipeline driver', function (): void {
    $options = app(RuntimeSettingRegistry::class)->options('ingestion.pipeline.driver');

    expect($options)->toHaveKey('laravel')
        ->and($options)->toHaveKey('go');
});

it('dispatches raw telemetry incoming events from go bridge payloads', function (): void {
    Event::fake([TelemetryIncoming::class]);

    $command = app(ConsumeTelemetryIngestionEvents::class);
    invokeBridgeHandler($command, 'handleIncomingPayload', new Payload(json_encode([
        'topic' => 'devices/sensor-01/telemetry',
        'device_uuid' => 'device-uuid',
        'device_external_id' => 'sensor-01',
        'payload' => ['temp_c' => 24.5],
        'received_at' => '2026-07-01T12:00:00Z',
    ], JSON_THROW_ON_ERROR), subject: 'iot.v1.ingestion.incoming'));

    Event::assertDispatched(TelemetryIncoming::class, function (TelemetryIncoming $event): bool {
        return $event->topic === 'devices/sensor-01/telemetry'
            && $event->deviceUuid === 'device-uuid'
            && $event->deviceExternalId === 'sensor-01'
            && $event->payload === ['temp_c' => 24.5];
    });
});

it('dispatches telemetry received events from go persisted payloads', function (): void {
    Event::fake([TelemetryReceived::class]);

    $ingestionMessage = IngestionMessage::factory()->create();
    $telemetryLog = DeviceTelemetryLog::factory()->create([
        'device_id' => $ingestionMessage->device_id,
        'device_profile_version_id' => $ingestionMessage->device_profile_version_id,
        'device_channel_id' => $ingestionMessage->device_channel_id,
        'ingestion_message_id' => $ingestionMessage->id,
    ]);

    $command = app(ConsumeTelemetryIngestionEvents::class);
    invokeBridgeHandler($command, 'handlePersistedPayload', new Payload(json_encode([
        'telemetry_log_id' => $telemetryLog->id,
        'ingestion_message_id' => $ingestionMessage->id,
    ], JSON_THROW_ON_ERROR), subject: 'iot.v1.ingestion.persisted'));

    Event::assertDispatched(TelemetryReceived::class, function (TelemetryReceived $event) use ($telemetryLog): bool {
        return $event->telemetryLog->is($telemetryLog);
    });
});

function invokeBridgeHandler(ConsumeTelemetryIngestionEvents $command, string $method, Payload $payload): void
{
    $reflection = new ReflectionMethod($command, $method);
    $reflection->invoke($command, $payload);
}
