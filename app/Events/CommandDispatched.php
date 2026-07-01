<?php

declare(strict_types=1);

namespace App\Events;

use App\Domain\DeviceControl\Models\DeviceCommandLog;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

class CommandDispatched implements ShouldBroadcastNow
{
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public DeviceCommandLog $commandLog,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        $deviceUuid = $this->commandLog->device->uuid ?? 'unknown';

        return [
            new Channel("device-control.{$deviceUuid}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'command.dispatched';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'command_log_id' => $this->commandLog->id,
            'device_uuid' => $this->commandLog->device?->uuid,
            'channel' => $this->commandLog->channel?->key,
            'channel_address' => $this->commandLog->channel?->address,
            'status' => $this->commandLog->status->value, /** @phpstan-ignore property.nonObject */
            'command_payload' => $this->commandLog->command_payload,
            'sent_at' => $this->commandLog->sent_at?->toIso8601String(), /** @phpstan-ignore method.nonObject */
            'created_at' => $this->commandLog->created_at?->toIso8601String(),
        ];
    }
}
