<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\AutomationThresholdPolicies\Pages;

use App\Filament\Portal\Resources\AutomationThresholdPolicies\AutomationThresholdPolicyResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAutomationThresholdPolicies extends ListRecords
{
    protected static string $resource = AutomationThresholdPolicyResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
