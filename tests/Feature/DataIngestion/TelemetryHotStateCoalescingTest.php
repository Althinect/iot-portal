<?php

declare(strict_types=1);

use App\Domain\DataIngestion\Contracts\HotStateStore;
use App\Domain\DataIngestion\Listeners\QueueTelemetryHotStateWrites;
use App\Domain\DataIngestion\Models\IngestionMessage;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\Shared\Services\RuntimeSettingManager;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use App\Events\TelemetryReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'cache.default' => 'array',
        'ingestion.hot_state_coalesce_seconds' => 1,
        'ingestion.side_effects_queue_connection' => 'redis',
        'ingestion.side_effects_queue' => 'telemetry-side-effects',
    ]);

    Cache::flush();
});

function fakeTelemetryHotStateStore(): object
{
    $store = new class implements HotStateStore
    {
        /**
         * @var array<int, array{device_id: int|string|null, channel_id: int|string|null, values: array<string, mixed>, ingestion_message_id: int|string|null, telemetry_log_id: string|null}>
         */
        public array $writes = [];

        public function store(
            Device $device,
            DeviceChannel $channel,
            array $finalValues,
            IngestionMessage $ingestionMessage,
            ?DeviceTelemetryLog $telemetryLog = null,
        ): void {
            $this->writes[] = [
                'device_id' => $device->getKey(),
                'channel_id' => $channel->getKey(),
                'values' => $finalValues,
                'ingestion_message_id' => $ingestionMessage->getKey(),
                'telemetry_log_id' => $telemetryLog?->getKey(),
            ];
        }
    };

    app()->instance(HotStateStore::class, $store);

    return $store;
}

/**
 * @param  array<string, mixed>  $values
 */
function createTelemetryHotStateLog(?Device $device = null, ?DeviceChannel $channel = null, array $values = ['value' => 10]): DeviceTelemetryLog
{
    $device ??= Device::factory()->create();
    $channel ??= DeviceChannel::factory()
        ->publish()
        ->create(['device_profile_version_id' => $device->device_profile_version_id]);

    $ingestionMessage = IngestionMessage::factory()->create([
        'organization_id' => $device->organization_id,
        'device_id' => $device->id,
        'device_profile_version_id' => $device->device_profile_version_id,
        'device_channel_id' => $channel->id,
        'source_subject' => $channel->address,
        'raw_payload' => $values,
    ]);

    return DeviceTelemetryLog::factory()
        ->forDevice($device)
        ->forChannel($channel)
        ->create([
            'ingestion_message_id' => $ingestionMessage->id,
            'raw_payload' => $values,
            'transformed_values' => $values,
            'recorded_at' => now(),
        ]);
}

it('coalesces hot-state writes to the latest telemetry log per device channel', function (): void {
    app(RuntimeSettingManager::class)->setGlobalOverrides([
        'ingestion.pipeline.hot_state_coalesce_seconds' => 2,
    ]);

    $store = fakeTelemetryHotStateStore();
    $firstTelemetryLog = createTelemetryHotStateLog(values: ['value' => 10]);
    $secondTelemetryLog = createTelemetryHotStateLog(
        device: $firstTelemetryLog->device,
        channel: $firstTelemetryLog->channel,
        values: ['value' => 20],
    );

    $listener = app(QueueTelemetryHotStateWrites::class);

    expect($listener->shouldQueue(new TelemetryReceived($firstTelemetryLog)))->toBeTrue()
        ->and($listener->shouldQueue(new TelemetryReceived($secondTelemetryLog)))->toBeFalse()
        ->and($listener->withDelay(new TelemetryReceived($firstTelemetryLog)))->toBe(2);

    $listener->handle(new TelemetryReceived($firstTelemetryLog));

    expect($store->writes)->toHaveCount(1)
        ->and($store->writes[0]['telemetry_log_id'])->toBe($secondTelemetryLog->id)
        ->and($store->writes[0]['values'])->toBe(['value' => 20]);
});

it('keeps per-row hot-state writes when coalescing is disabled', function (): void {
    app(RuntimeSettingManager::class)->setGlobalOverrides([
        'ingestion.pipeline.hot_state_coalesce_seconds' => 0,
    ]);

    $store = fakeTelemetryHotStateStore();
    $firstTelemetryLog = createTelemetryHotStateLog(values: ['value' => 10]);
    $secondTelemetryLog = createTelemetryHotStateLog(
        device: $firstTelemetryLog->device,
        channel: $firstTelemetryLog->channel,
        values: ['value' => 20],
    );

    $listener = app(QueueTelemetryHotStateWrites::class);

    expect($listener->shouldQueue(new TelemetryReceived($firstTelemetryLog)))->toBeTrue()
        ->and($listener->shouldQueue(new TelemetryReceived($secondTelemetryLog)))->toBeTrue()
        ->and($listener->withDelay(new TelemetryReceived($firstTelemetryLog)))->toBe(0);

    $listener->handle(new TelemetryReceived($firstTelemetryLog));
    $listener->handle(new TelemetryReceived($secondTelemetryLog));

    expect($store->writes)->toHaveCount(2)
        ->and($store->writes[0]['telemetry_log_id'])->toBe($firstTelemetryLog->id)
        ->and($store->writes[1]['telemetry_log_id'])->toBe($secondTelemetryLog->id);
});
