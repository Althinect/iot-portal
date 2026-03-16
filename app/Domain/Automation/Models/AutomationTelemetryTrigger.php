<?php

declare(strict_types=1);

namespace App\Domain\Automation\Models;

use App\Domain\Automation\Services\AutomationTriggerCacheInvalidator;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Models\DeviceType;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Domain\Shared\Models\Organization;
use Database\Factories\Domain\Automation\Models\AutomationTelemetryTriggerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationTelemetryTrigger extends Model
{
    /** @use HasFactory<AutomationTelemetryTriggerFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filter_expression' => 'array',
        ];
    }

    protected static function newFactory(): AutomationTelemetryTriggerFactory
    {
        return AutomationTelemetryTriggerFactory::new();
    }

    protected static function booted(): void
    {
        static::saved(static function (): void {
            app(AutomationTriggerCacheInvalidator::class)->bumpVersion();
        });

        static::deleted(static function (): void {
            app(AutomationTriggerCacheInvalidator::class)->bumpVersion();
        });
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<AutomationWorkflowVersion, $this> */
    public function workflowVersion(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflowVersion::class, 'workflow_version_id');
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @return BelongsTo<DeviceType, $this> */
    public function deviceType(): BelongsTo
    {
        return $this->belongsTo(DeviceType::class);
    }

    /** @return BelongsTo<SchemaVersionTopic, $this> */
    public function schemaVersionTopic(): BelongsTo
    {
        return $this->belongsTo(SchemaVersionTopic::class);
    }
}
