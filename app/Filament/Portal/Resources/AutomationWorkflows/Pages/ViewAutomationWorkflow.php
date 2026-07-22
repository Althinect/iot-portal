<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\AutomationWorkflows\Pages;

use App\Domain\Automation\Models\AutomationWorkflow;
use App\Filament\Portal\Resources\AutomationWorkflows\AutomationWorkflowResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewAutomationWorkflow extends ViewRecord
{
    protected static string $resource = AutomationWorkflowResource::class;

    protected function getHeaderActions(): array
    {
        $record = $this->getRecord();

        return [
            Actions\Action::make('dagEditor')
                ->label('DAG Editor')
                ->icon(Heroicon::OutlinedSquare3Stack3d)
                ->url(fn (): string => AutomationWorkflowResource::getUrl('dag-editor', ['record' => $record]))
                ->visible(fn (): bool => $record instanceof AutomationWorkflow && ! $record->is_managed),
            Actions\EditAction::make()
                ->visible(fn (): bool => $record instanceof AutomationWorkflow && ! $record->is_managed),
        ];
    }
}
