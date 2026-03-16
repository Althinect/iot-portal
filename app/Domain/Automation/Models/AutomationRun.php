<?php

declare(strict_types=1);

namespace App\Domain\Automation\Models;

use App\Domain\Automation\Enums\AutomationRunStatus;
use App\Domain\Shared\Models\Organization;
use Database\Factories\Domain\Automation\Models\AutomationRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationRun extends Model
{
    /** @use HasFactory<AutomationRunFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger_payload' => 'array',
            'error_summary' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'status' => AutomationRunStatus::class,
        ];
    }

    protected static function newFactory(): AutomationRunFactory
    {
        return AutomationRunFactory::new();
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<AutomationWorkflow, $this> */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflow::class, 'workflow_id');
    }

    /** @return BelongsTo<AutomationWorkflowVersion, $this> */
    public function workflowVersion(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflowVersion::class, 'workflow_version_id');
    }

    /** @return HasMany<AutomationRunStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(AutomationRunStep::class, 'automation_run_id');
    }
}
