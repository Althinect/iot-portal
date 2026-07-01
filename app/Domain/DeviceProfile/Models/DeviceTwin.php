<?php

declare(strict_types=1);

namespace App\Domain\DeviceProfile\Models;

use App\Domain\DeviceManagement\Models\Device;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $device_id
 * @property array<string, mixed>|null $tags
 * @property array<string, mixed>|null $desired
 * @property array<string, mixed>|null $reported
 * @property string|null $etag
 * @property int $desired_version
 * @property int $reported_version
 */
class DeviceTwin extends Model
{
    protected $guarded = ['id'];

    protected $primaryKey = 'device_id';

    public $incrementing = false;

    protected $keyType = 'int';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'desired' => 'array',
            'reported' => 'array',
            'desired_version' => 'int',
            'reported_version' => 'int',
            'desired_updated_at' => 'datetime',
            'reported_updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
