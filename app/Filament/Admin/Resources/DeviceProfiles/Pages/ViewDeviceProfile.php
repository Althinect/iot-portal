<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DeviceProfiles\Pages;

use App\Filament\Admin\Resources\DeviceProfiles\DeviceProfileResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewDeviceProfile extends ViewRecord
{
    protected static string $resource = DeviceProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
