<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\AutomationThresholdPolicies\Pages;

use App\Domain\Automation\Models\AutomationThresholdPolicy;
use App\Domain\Automation\Services\ThresholdPolicyWorkflowProjector;
use App\Filament\Admin\Resources\AutomationThresholdPolicies\AutomationThresholdPolicyResource as AdminThresholdPolicyResource;
use App\Filament\Portal\Resources\AutomationThresholdPolicies\AutomationThresholdPolicyResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateAutomationThresholdPolicy extends CreateRecord
{
    protected static string $resource = AutomationThresholdPolicyResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['organization_id'] = Filament::getTenant()?->getKey();

        return AdminThresholdPolicyResource::prepareThresholdPolicyFormData($data);
    }

    protected function afterCreate(): void
    {
        $policy = $this->getRecord();

        if ($policy instanceof AutomationThresholdPolicy) {
            app(ThresholdPolicyWorkflowProjector::class)->sync($policy);
        }
    }
}
