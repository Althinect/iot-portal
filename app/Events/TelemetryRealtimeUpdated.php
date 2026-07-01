<?php

declare(strict_types=1);

namespace App\Events;

use App\Domain\IoTDashboard\Application\RealtimeStreamChannel;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;

class TelemetryRealtimeUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;

    public function __construct(
        public readonly string $telemetryLogId,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $telemetryLog = $this->telemetryLog(['device:id,uuid']);

        if (! $telemetryLog instanceof DeviceTelemetryLog) {
            return [];
        }

        $channelName = RealtimeStreamChannel::forTelemetryLog($telemetryLog);

        if (! is_string($channelName)) {
            return [];
        }

        return [
            new PrivateChannel($channelName),
        ];
    }

    public function broadcastAs(): string
    {
        return 'telemetry.received';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $telemetryLog = $this->telemetryLog([
            'device:id,uuid,organization_id',
            'channel:id,key',
        ]);

        if (! $telemetryLog instanceof DeviceTelemetryLog) {
            return [
                'id' => $this->telemetryLogId,
            ];
        }

        $device = $telemetryLog->device;
        $recordedAt = $telemetryLog->getAttribute('recorded_at');
        $recordedAtValue = $recordedAt instanceof Carbon ? $recordedAt->toIso8601String() : null;

        return [
            'id' => $telemetryLog->id,
            'organization_id' => is_numeric($device?->organization_id) ? (int) $device->organization_id : null,
            'device_uuid' => $device?->uuid,
            'device_channel_id' => $telemetryLog->device_channel_id,
            'channel_key' => $telemetryLog->channel?->key,
            'transformed_values' => $telemetryLog->transformed_values,
            'recorded_at' => $recordedAtValue,
        ];
    }

    /**
     * @param  array<int, string>  $with
     */
    private function telemetryLog(array $with = []): ?DeviceTelemetryLog
    {
        if (trim($this->telemetryLogId) === '') {
            return null;
        }

        return DeviceTelemetryLog::query()
            ->with($with)
            ->whereKey($this->telemetryLogId)
            ->first();
    }
}
