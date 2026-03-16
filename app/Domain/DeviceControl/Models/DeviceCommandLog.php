<?php

declare(strict_types=1);

namespace App\Domain\DeviceControl\Models;

use App\Domain\DeviceControl\Enums\CommandStatus;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
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
     * @return BelongsTo<SchemaVersionTopic, $this>
     */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(SchemaVersionTopic::class, 'schema_version_topic_id');
    }

    /**
     * @return BelongsTo<SchemaVersionTopic, $this>
     */
    public function responseTopic(): BelongsTo
    {
        return $this->belongsTo(SchemaVersionTopic::class, 'response_schema_version_topic_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
