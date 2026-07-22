<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\AutomationWorkflows\Pages;

use App\Domain\Automation\Models\AutomationWorkflow;
use App\Filament\Admin\Resources\Automation\AutomationWorkflows\Pages\EditAutomationDag as AdminEditAutomationDag;
use App\Filament\Portal\Resources\AutomationWorkflows\AutomationWorkflowResource;

class EditAutomationDag extends AdminEditAutomationDag
{
    protected static string $resource = AutomationWorkflowResource::class;

    protected function managedWorkflowRedirectUrl(AutomationWorkflow $workflow): string
    {
        return AutomationWorkflowResource::getUrl('view', ['record' => $workflow]);
    }
}
