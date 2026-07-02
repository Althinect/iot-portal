<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DeviceProfileVersions\Pages;

use App\Filament\Admin\Resources\DeviceProfileVersions\DeviceProfileVersionResource;
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
