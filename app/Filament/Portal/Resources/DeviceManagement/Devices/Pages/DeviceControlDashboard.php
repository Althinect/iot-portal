<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\DeviceManagement\Devices\Pages;

use App\Filament\Admin\Resources\DeviceManagement\Devices\Pages\DeviceControlDashboard as AdminDeviceControlDashboard;
use App\Filament\Portal\Resources\DeviceManagement\Devices\DeviceResource;
use Filament\Actions\Action;

class DeviceControlDashboard extends AdminDeviceControlDashboard
{
    protected static string $resource = DeviceResource::class;

    public function getHeaderActions(): array
    {
        return [
            Action::make('viewDevice')
                ->label('View Device')
                ->url(fn (): string => DeviceResource::getUrl('view', ['record' => $this->getRecord()])),
        ];
    }
}
