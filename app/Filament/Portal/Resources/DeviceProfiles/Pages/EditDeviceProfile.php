<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\DeviceProfiles\Pages;

use App\Filament\Portal\Resources\DeviceProfiles\DeviceProfileResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDeviceProfile extends EditRecord
{
    protected static string $resource = DeviceProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make()->label('Archive'),
            Actions\RestoreAction::make(),
        ];
    }
}
