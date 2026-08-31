<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\DeviceProfileVersions\Pages;

use App\Filament\Portal\Resources\DeviceProfileVersions\DeviceProfileVersionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDeviceProfileVersions extends ListRecords
{
    protected static string $resource = DeviceProfileVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
