<?php

declare(strict_types=1);

namespace App\Domain\DeviceProfile\Models;

use App\Domain\DeviceProfile\Enums\ChannelLinkType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceChannelLink extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'link_type' => ChannelLinkType::class,
        ];
    }

    /**
     * @return BelongsTo<DeviceChannel, $this>
     */
    public function fromChannel(): BelongsTo
    {
        return $this->belongsTo(DeviceChannel::class, 'from_device_channel_id');
    }

    /**
     * @return BelongsTo<DeviceChannel, $this>
     */
    public function toChannel(): BelongsTo
    {
        return $this->belongsTo(DeviceChannel::class, 'to_device_channel_id');
    }
}
