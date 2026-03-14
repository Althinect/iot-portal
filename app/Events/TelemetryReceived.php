<?php

declare(strict_types=1);

namespace App\Events;

use App\Domain\DataIngestion\Concerns\InteractsWithTelemetrySideEffectsQueue;
use App\Domain\IoTDashboard\Application\RealtimeStreamChannel;
use App\Domain\Shared\Services\RuntimeSettingManager;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class TelemetryReceived implements ShouldBroadcast
{
    use InteractsWithSockets;
    use InteractsWithTelemetrySideEffectsQueue;
    use SerializesModels;

    public string $connection;

    public string $queue;

    public function __construct(
        public DeviceTelemetryLog $telemetryLog,
    ) {
        $this->connection = $this->resolveTelemetrySideEffectsConnection();
        $this->queue = $this->resolveTelemetrySideEffectsQueue();
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        if (! app(RuntimeSettingManager::class)->booleanValue('ingestion.pipeline.broadcast_realtime', $this->telemetryLog->device?->organization_id)) {
            return [];
        }

        $channelName = RealtimeStreamChannel::forTelemetryLog($this->telemetryLog);

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

    public function broadcastQueue(): string
    {
        return $this->queue;
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $device = $this->telemetryLog->device;
        $recordedAt = $this->telemetryLog->getAttribute('recorded_at');
        $recordedAtValue = $recordedAt instanceof Carbon ? $recordedAt->toIso8601String() : null;

        return [
            'id' => $this->telemetryLog->id,
            'organization_id' => is_numeric($device?->organization_id) ? (int) $device->organization_id : null,
            'device_uuid' => $device?->uuid,
            'schema_version_topic_id' => $this->telemetryLog->schema_version_topic_id,
            'transformed_values' => $this->telemetryLog->transformed_values,
            'recorded_at' => $recordedAtValue,
        ];
    }
}
