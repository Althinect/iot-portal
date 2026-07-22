<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\AutomationWorkflows\Pages;

use App\Domain\Shared\Models\Organization;
use App\Filament\Admin\Resources\Automation\AutomationWorkflows\Pages\CreateAutomationWorkflow as AdminCreateAutomationWorkflow;
use App\Filament\Portal\Resources\AutomationWorkflows\AutomationWorkflowResource;
use Filament\Facades\Filament;
use RuntimeException;

class CreateAutomationWorkflow extends AdminCreateAutomationWorkflow
{
    protected static string $resource = AutomationWorkflowResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = parent::mutateFormDataBeforeCreate($data);
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Organization) {
            throw new RuntimeException('An organization tenant is required to create an automation.');
        }

        $tenantId = $tenant->getKey();

        if (! is_numeric($tenantId)) {
            throw new RuntimeException('Unable to resolve the organization tenant id.');
        }

        $data['organization_id'] = (int) $tenantId;

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return AutomationWorkflowResource::getUrl('dag-editor', ['record' => $this->getRecord()]);
    }
}
