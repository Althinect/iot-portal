<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Automation\Models\AutomationWorkflow;
use App\Domain\Automation\Permissions\AutomationWorkflowPermission;
use App\Domain\Shared\Models\User;

class AutomationWorkflowPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->belongsToAnOrganization($user)
            || $user->hasPermissionTo(AutomationWorkflowPermission::VIEW_ANY);
    }

    public function view(User $user, AutomationWorkflow $automationWorkflow): bool
    {
        return $this->belongsToWorkflowOrganization($user, $automationWorkflow)
            || $user->hasPermissionTo(AutomationWorkflowPermission::VIEW);
    }

    public function create(User $user): bool
    {
        return $this->belongsToAnOrganization($user)
            || $user->hasPermissionTo(AutomationWorkflowPermission::CREATE);
    }

    public function update(User $user, AutomationWorkflow $automationWorkflow): bool
    {
        return $this->belongsToWorkflowOrganization($user, $automationWorkflow)
            || $user->hasPermissionTo(AutomationWorkflowPermission::UPDATE);
    }

    public function delete(User $user, AutomationWorkflow $automationWorkflow): bool
    {
        return $this->belongsToWorkflowOrganization($user, $automationWorkflow)
            || $user->hasPermissionTo(AutomationWorkflowPermission::DELETE);
    }

    public function restore(User $user, AutomationWorkflow $automationWorkflow): bool
    {
        return $user->hasPermissionTo(AutomationWorkflowPermission::RESTORE);
    }

    public function forceDelete(User $user, AutomationWorkflow $automationWorkflow): bool
    {
        return $user->hasPermissionTo(AutomationWorkflowPermission::FORCE_DELETE);
    }

    public function publish(User $user, AutomationWorkflow $automationWorkflow): bool
    {
        return $this->belongsToWorkflowOrganization($user, $automationWorkflow)
            || $user->hasPermissionTo(AutomationWorkflowPermission::PUBLISH);
    }

    private function belongsToAnOrganization(User $user): bool
    {
        return $user->organizations()->exists();
    }

    private function belongsToWorkflowOrganization(User $user, AutomationWorkflow $automationWorkflow): bool
    {
        return $user->organizations()
            ->whereKey($automationWorkflow->organization_id)
            ->exists();
    }
}
