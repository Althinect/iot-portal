<?php

declare(strict_types=1);

namespace App\Domain\Automation\Models;

use App\Domain\Shared\Models\Organization;
use Database\Factories\Domain\Automation\Models\AutomationScheduleTriggerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationScheduleTrigger extends Model
{
    /** @use HasFactory<AutomationScheduleTriggerFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'next_run_at' => 'datetime',
            'active' => 'boolean',
        ];
    }

    protected static function newFactory(): AutomationScheduleTriggerFactory
    {
        return AutomationScheduleTriggerFactory::new();
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
}
