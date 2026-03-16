<?php

declare(strict_types=1);

namespace App\Domain\DeviceControl\Models;

use App\Domain\DeviceManagement\Models\Device;
use Database\Factories\Domain\DeviceControl\Models\DeviceDesiredStateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceDesiredState extends Model
{
    /** @use HasFactory<DeviceDesiredStateFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'desired_state' => 'array',
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

    public function isReconciled(): bool
    {
        return $this->reconciled_at !== null;
    }
}
