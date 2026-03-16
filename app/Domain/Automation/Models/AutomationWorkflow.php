<?php

declare(strict_types=1);

namespace App\Domain\Automation\Models;

use App\Domain\Automation\Enums\AutomationWorkflowStatus;
use App\Domain\Automation\Services\AutomationTriggerCacheInvalidator;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use Database\Factories\Domain\Automation\Models\AutomationWorkflowFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationWorkflow extends Model
{
    /** @use HasFactory<AutomationWorkflowFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AutomationWorkflowStatus::class,
        ];
    }

    protected static function newFactory(): AutomationWorkflowFactory
    {
        return AutomationWorkflowFactory::new();
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
    public function activeVersion(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflowVersion::class, 'active_version_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** @return HasMany<AutomationWorkflowVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(AutomationWorkflowVersion::class);
    }

    /** @return HasMany<AutomationRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class, 'workflow_id');
    }
}
