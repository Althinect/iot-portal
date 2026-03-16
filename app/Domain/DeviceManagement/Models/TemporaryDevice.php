<?php

declare(strict_types=1);

namespace App\Domain\DeviceManagement\Models;

use Database\Factories\Domain\DeviceManagement\Models\TemporaryDeviceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class TemporaryDevice extends Model
{
    /** @use HasFactory<TemporaryDeviceFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected static function newFactory(): TemporaryDeviceFactory
    {
        return TemporaryDeviceFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
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
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeExpired(Builder $query, ?Carbon $now = null): Builder
    {
        return $query->where('expires_at', '<=', $now ?? now());
    }
}
