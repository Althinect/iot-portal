<?php

declare(strict_types=1);

namespace App\Domain\DeviceSchema\Models;

use App\Domain\DeviceManagement\Models\DeviceType;
use Database\Factories\Domain\DeviceSchema\Models\DeviceSchemaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

class DeviceSchema extends Model
{
    /** @use HasFactory<DeviceSchemaFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = ['id'];

    /**
     * @return BelongsTo<DeviceType, $this>
     */
    public function deviceType(): BelongsTo
    {
        return $this->belongsTo(DeviceType::class);
    }

    /**
     * @return HasMany<DeviceSchemaVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(DeviceSchemaVersion::class);
    }

    /**
     * @return HasManyThrough<SchemaVersionTopic, DeviceSchemaVersion, $this>
     */
    public function topics(): HasManyThrough
    {
        return $this->hasManyThrough(
            SchemaVersionTopic::class,
            DeviceSchemaVersion::class,
            'device_schema_id',
            'device_schema_version_id',
        );
    }
}
