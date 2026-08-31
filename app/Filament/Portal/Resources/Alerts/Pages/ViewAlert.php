<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\Alerts\Pages;

use App\Filament\Portal\Resources\Alerts\AlertResource;
use App\Filament\Portal\Resources\Alerts\Tables\AlertsTable;
use Filament\Resources\Pages\ViewRecord;

class ViewAlert extends ViewRecord
{
    protected static string $resource = AlertResource::class;

    protected function getHeaderActions(): array
    {
        return [AlertsTable::acknowledgeAction()];
    }
}
