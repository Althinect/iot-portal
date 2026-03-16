<?php

declare(strict_types=1);

namespace App\Domain\DataIngestion\Models;

use App\Domain\DataIngestion\Enums\IngestionStatus;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Domain\Shared\Models\Organization;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use Database\Factories\Domain\DataIngestion\Models\IngestionMessageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class IngestionMessage extends Model
{
    /** @use HasFactory<IngestionMessageFactory> */
    use HasFactory;

    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected static function newFactory(): IngestionMessageFactory
    {
        return IngestionMessageFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'error_summary' => 'array',
            'status' => IngestionStatus::class,
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * @return BelongsTo<DeviceSchemaVersion, $this>
     */
    public function schemaVersion(): BelongsTo
    {
        return $this->belongsTo(DeviceSchemaVersion::class, 'device_schema_version_id');
    }

    /**
     * @return BelongsTo<SchemaVersionTopic, $this>
     */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(SchemaVersionTopic::class, 'schema_version_topic_id');
    }

    /**
     * @return HasMany<IngestionStageLog, $this>
     */
    public function stageLogs(): HasMany
    {
        return $this->hasMany(IngestionStageLog::class);
    }

    /**
     * @return HasOne<DeviceTelemetryLog, $this>
     */
    public function telemetryLog(): HasOne
    {
        return $this->hasOne(DeviceTelemetryLog::class, 'ingestion_message_id');
    }
}
