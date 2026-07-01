<?php

declare(strict_types=1);

namespace App\Domain\DeviceControl\Models;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use Database\Factories\Domain\DeviceControl\Models\DeviceDesiredChannelStateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceDesiredChannelState extends Model
{
    /** @use HasFactory<DeviceDesiredChannelStateFactory> */
    use HasFactory;

    protected $table = 'device_desired_channel_states';

    protected $guarded = ['id'];

    protected static function newFactory(): DeviceDesiredChannelStateFactory
    {
        return DeviceDesiredChannelStateFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'desired_payload' => 'array',
            'reconciled_at' => 'datetime',
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

    public function isReconciled(): bool
    {
        return $this->reconciled_at !== null;
    }
}
