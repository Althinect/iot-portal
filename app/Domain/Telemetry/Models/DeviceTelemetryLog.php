<?php

declare(strict_types=1);

namespace App\Domain\Telemetry\Models;

use App\Domain\DataIngestion\Models\IngestionMessage;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\Telemetry\Enums\ValidationStatus;
use Database\Factories\Domain\Telemetry\Models\DeviceTelemetryLogFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $device_id
 * @property Carbon $recorded_at
 * @property array<string, mixed>|null $transformed_values
 */
class DeviceTelemetryLog extends Model
{
    /** @use HasFactory<DeviceTelemetryLogFactory> */
    use HasFactory;

    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected static function newFactory(): DeviceTelemetryLogFactory
    {
        return DeviceTelemetryLogFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'validation_errors' => 'array',
            'mutated_values' => 'array',
            'transformed_values' => 'array',
            'validation_status' => ValidationStatus::class,
            'processing_state' => 'string',
            'recorded_at' => 'datetime',
            'received_at' => 'datetime',
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
     * @return BelongsTo<DeviceProfileVersion, $this>
     */
    public function profileVersion(): BelongsTo
    {
        return $this->belongsTo(DeviceProfileVersion::class, 'device_profile_version_id');
    }

    /**
     * @return BelongsTo<DeviceChannel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(DeviceChannel::class, 'device_channel_id');
    }

    /**
     * @return BelongsTo<IngestionMessage, $this>
     */
    public function ingestionMessage(): BelongsTo
    {
        return $this->belongsTo(IngestionMessage::class, 'ingestion_message_id');
    }
}
