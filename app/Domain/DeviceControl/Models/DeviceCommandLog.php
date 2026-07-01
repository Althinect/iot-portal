<?php

declare(strict_types=1);

namespace App\Domain\DeviceControl\Models;

use App\Domain\DeviceControl\Enums\CommandStatus;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\Shared\Models\User;
use Database\Factories\Domain\DeviceControl\Models\DeviceCommandLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceCommandLog extends Model
{
    /** @use HasFactory<DeviceCommandLogFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'command_payload' => 'array',
            'response_payload' => 'array',
            'correlation_id' => 'string',
            'status' => CommandStatus::class,
            'sent_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * @return BelongsTo<DeviceChannel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(DeviceChannel::class, 'device_channel_id');
    }

    /**
     * @return BelongsTo<DeviceChannel, $this>
     */
    public function responseChannel(): BelongsTo
    {
        return $this->belongsTo(DeviceChannel::class, 'response_device_channel_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
