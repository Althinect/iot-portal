<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AutomationThresholdPolicies\Pages;

use App\Domain\Automation\Models\AutomationThresholdPolicy;
use App\Domain\Automation\Services\ThresholdPolicyWorkflowProjector;
use App\Filament\Admin\Resources\AutomationThresholdPolicies\AutomationThresholdPolicyResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAutomationThresholdPolicy extends EditRecord
{
    protected static string $resource = AutomationThresholdPolicyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
            Actions\ForceDeleteAction::make(),
            Actions\RestoreAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $recordId = is_numeric($this->getRecord()->getKey())
            ? (int) $this->getRecord()->getKey()
            : null;

        return AutomationThresholdPolicyResource::prepareThresholdPolicyFormData($data, $recordId);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        return $record instanceof AutomationThresholdPolicy
            ? AutomationThresholdPolicyResource::prepareThresholdPolicyFormDataBeforeFill($data, $record)
            : $data;
    }

    protected function afterSave(): void
    {
        $policy = $this->getRecord();

        if ($policy instanceof AutomationThresholdPolicy) {
            app(ThresholdPolicyWorkflowProjector::class)->sync($policy);
        }
    }
}
