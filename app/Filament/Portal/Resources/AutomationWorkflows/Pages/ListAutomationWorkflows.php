<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\AutomationWorkflows\Pages;

use App\Filament\Portal\Resources\AutomationWorkflows\AutomationWorkflowResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAutomationWorkflows extends ListRecords
{
    protected static string $resource = AutomationWorkflowResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
