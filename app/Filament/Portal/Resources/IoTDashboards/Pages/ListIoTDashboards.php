<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\IoTDashboards\Pages;

use App\Filament\Portal\Resources\IoTDashboards\IoTDashboardResource;
use Filament\Resources\Pages\ListRecords;

class ListIoTDashboards extends ListRecords
{
    protected static string $resource = IoTDashboardResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
