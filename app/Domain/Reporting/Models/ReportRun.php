<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Models;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\Reporting\Enums\ReportGrouping;
use App\Domain\Reporting\Enums\ReportRunStatus;
use App\Domain\Reporting\Enums\ReportType;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use Database\Factories\Domain\Reporting\Models\ReportRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $device_id
 * @property int|null $requested_by_user_id
 * @property ReportType $type
 * @property ReportRunStatus $status
 * @property ReportGrouping|null $grouping
 * @property array<int, string>|null $parameter_keys
 * @property array<string, mixed>|null $payload
 * @property array<string, mixed>|null $meta
 * @property Carbon $from_at
 * @property Carbon $until_at
 * @property Carbon|null $generated_at
 * @property Carbon|null $failed_at
 * @property int|null $row_count
 * @property int|null $file_size
 * @property string|null $storage_disk
 * @property string|null $storage_path
 * @property string|null $file_name
 * @property string|null $failure_reason
 * @property string $timezone
 * @property string|null $format
 * @property Organization|null $organization
 * @property Device|null $device
 * @property User|null $requestedBy
 */
class ReportRun extends Model
{
    /** @use HasFactory<ReportRunFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected static function newFactory(): ReportRunFactory
    {
        return ReportRunFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ReportType::class,
            'status' => ReportRunStatus::class,
            'grouping' => ReportGrouping::class,
            'parameter_keys' => 'array',
            'payload' => 'array',
            'meta' => 'array',
            'from_at' => 'datetime',
            'until_at' => 'datetime',
            'generated_at' => 'datetime',
            'failed_at' => 'datetime',
            'row_count' => 'integer',
            'file_size' => 'integer',
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
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function isDownloadable(): bool
    {
        return $this->status === ReportRunStatus::Completed
            && is_string($this->storage_disk)
            && trim($this->storage_disk) !== ''
            && is_string($this->storage_path)
            && trim($this->storage_path) !== '';
    }
}
