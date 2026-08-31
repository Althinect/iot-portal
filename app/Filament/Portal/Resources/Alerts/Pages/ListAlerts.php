<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\Alerts\Pages;

use App\Filament\Portal\Resources\Alerts\AlertResource;
use Filament\Resources\Pages\ListRecords;

class ListAlerts extends ListRecords
{
    protected static string $resource = AlertResource::class;
}
