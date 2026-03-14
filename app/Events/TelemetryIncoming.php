<?php

declare(strict_types=1);

namespace App\Events;

use App\Domain\Shared\Services\RuntimeSettingManager;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class TelemetryIncoming implements ShouldBroadcastNow
{
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $topic,
        public ?string $deviceUuid,
        public ?string $deviceExternalId,
        public array $payload,
        public ?Carbon $receivedAt = null,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        if (! app(RuntimeSettingManager::class)->booleanValue('iot.diagnostics.raw_telemetry_stream')) {
            return [];
        }

        return [
            new Channel('telemetry'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'telemetry.incoming';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'topic' => $this->topic,
            'device_uuid' => $this->deviceUuid,
            'device_external_id' => $this->deviceExternalId,
            'payload' => $this->payload,
            'received_at' => ($this->receivedAt ?? Carbon::now())->toIso8601String(),
        ];
    }
}
