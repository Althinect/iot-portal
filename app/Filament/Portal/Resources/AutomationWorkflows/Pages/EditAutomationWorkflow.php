<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\AutomationWorkflows\Pages;

use App\Filament\Admin\Resources\Automation\AutomationWorkflows\Pages\EditAutomationWorkflow as AdminEditAutomationWorkflow;
use App\Filament\Portal\Resources\AutomationWorkflows\AutomationWorkflowResource;
use Filament\Actions;
use Filament\Support\Icons\Heroicon;

class EditAutomationWorkflow extends AdminEditAutomationWorkflow
{
    protected static string $resource = AutomationWorkflowResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\Action::make('dagEditor')
                ->label('DAG Editor')
                ->icon(Heroicon::OutlinedSquare3Stack3d)
                ->url(fn (): string => AutomationWorkflowResource::getUrl('dag-editor', ['record' => $this->getRecord()])),
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['organization_id']);

        return parent::mutateFormDataBeforeSave($data);
    }
}
