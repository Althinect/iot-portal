<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\IoTDashboards\Pages;

use App\Filament\Portal\Resources\IoTDashboards\IoTDashboardResource;
use Filament\Resources\Pages\CreateRecord;

class CreateIoTDashboard extends CreateRecord
{
    protected static string $resource = IoTDashboardResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return IoTDashboardResource::prepareTenantFormData($data);
    }
}
