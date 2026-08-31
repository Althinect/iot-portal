<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Authorization\Services\TenantAuthorization;
use App\Domain\Automation\Models\AutomationWorkflow;
use App\Domain\Automation\Permissions\AutomationWorkflowPermission;
use App\Domain\Shared\Models\User;

class AutomationWorkflowPolicy
{
    public function __construct(private TenantAuthorization $authorization) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->allows($user, AutomationWorkflowPermission::VIEW_ANY);
    }

    public function view(User $user, AutomationWorkflow $automationWorkflow): bool
    {
        return $this->authorization->allows(
            $user,
            AutomationWorkflowPermission::VIEW,
            $automationWorkflow->organization_id,
        );
    }

    public function create(User $user): bool
    {
        return $this->authorization->allows($user, AutomationWorkflowPermission::CREATE);
    }

    public function update(User $user, AutomationWorkflow $automationWorkflow): bool
    {
        return $this->authorization->allows(
            $user,
            AutomationWorkflowPermission::UPDATE,
            $automationWorkflow->organization_id,
        );
    }

    public function delete(User $user, AutomationWorkflow $automationWorkflow): bool
    {
        return $this->authorization->allows(
            $user,
            AutomationWorkflowPermission::ARCHIVE,
            $automationWorkflow->organization_id,
        );
    }

    public function restore(User $user, AutomationWorkflow $automationWorkflow): bool
    {
        return $this->authorization->allows(
            $user,
            AutomationWorkflowPermission::RESTORE,
            $automationWorkflow->organization_id,
        );
    }

    public function forceDelete(User $user, AutomationWorkflow $automationWorkflow): bool
    {
        return $user->isSuperAdmin();
    }

    public function publish(User $user, AutomationWorkflow $automationWorkflow): bool
    {
        return $this->authorization->allows(
            $user,
            AutomationWorkflowPermission::PUBLISH,
            $automationWorkflow->organization_id,
        );
    }
}
