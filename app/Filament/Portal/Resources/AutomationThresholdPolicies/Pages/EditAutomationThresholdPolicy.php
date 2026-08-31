<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\AutomationThresholdPolicies\Pages;

use App\Domain\Automation\Models\AutomationThresholdPolicy;
use App\Domain\Automation\Services\ThresholdPolicyWorkflowProjector;
use App\Filament\Admin\Resources\AutomationThresholdPolicies\AutomationThresholdPolicyResource as AdminThresholdPolicyResource;
use App\Filament\Portal\Resources\AutomationThresholdPolicies\AutomationThresholdPolicyResource;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;

class EditAutomationThresholdPolicy extends EditRecord
{
    protected static string $resource = AutomationThresholdPolicyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make()->label('Archive'),
            Actions\RestoreAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->record instanceof AutomationThresholdPolicy
            ? AdminThresholdPolicyResource::prepareThresholdPolicyFormDataBeforeFill($data, $this->record)
            : $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['organization_id'] = Filament::getTenant()?->getKey();

        return AdminThresholdPolicyResource::prepareThresholdPolicyFormData(
            $data,
            $this->record instanceof AutomationThresholdPolicy ? (int) $this->record->id : null,
        );
    }

    protected function afterSave(): void
    {
        $policy = $this->getRecord();

        if ($policy instanceof AutomationThresholdPolicy) {
            app(ThresholdPolicyWorkflowProjector::class)->sync($policy);
        }
    }
}
