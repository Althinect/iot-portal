<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\IoTDashboards\Pages;

use App\Filament\Portal\Resources\IoTDashboards\IoTDashboardResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditIoTDashboard extends EditRecord
{
    protected static string $resource = IoTDashboardResource::class;

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
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return IoTDashboardResource::prepareTenantFormData($data);
    }
}
