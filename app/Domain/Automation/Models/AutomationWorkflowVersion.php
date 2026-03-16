<?php

declare(strict_types=1);

namespace App\Domain\Automation\Models;

use App\Domain\Automation\Services\AutomationTriggerCacheInvalidator;
use Database\Factories\Domain\Automation\Models\AutomationWorkflowVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationWorkflowVersion extends Model
{
    /** @use HasFactory<AutomationWorkflowVersionFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'graph_json' => 'array',
            'published_at' => 'datetime',
        ];
    }

    protected static function newFactory(): AutomationWorkflowVersionFactory
    {
        return AutomationWorkflowVersionFactory::new();
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

    /** @return BelongsTo<AutomationWorkflow, $this> */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflow::class, 'automation_workflow_id');
    }

    /** @return HasMany<AutomationTelemetryTrigger, $this> */
    public function telemetryTriggers(): HasMany
    {
        return $this->hasMany(AutomationTelemetryTrigger::class, 'workflow_version_id');
    }

    /** @return HasMany<AutomationScheduleTrigger, $this> */
    public function scheduleTriggers(): HasMany
    {
        return $this->hasMany(AutomationScheduleTrigger::class, 'workflow_version_id');
    }
}
