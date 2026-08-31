<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\DeviceManagement\Devices\Pages;

use App\Filament\Admin\Resources\DeviceManagement\Devices\Pages\DeviceControlDashboard as AdminDeviceControlDashboard;
use App\Filament\Portal\Resources\DeviceManagement\Devices\DeviceResource;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Gate;

class DeviceControlDashboard extends AdminDeviceControlDashboard
{
    protected static string $resource = DeviceResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        Gate::authorize('control', $this->getRecord());
    }

    public function sendCommand(): void
    {
        Gate::authorize('control', $this->getRecord());
        parent::sendCommand();
    }

    public function getHeaderActions(): array
    {
        return [
            Action::make('viewDevice')
                ->label('View Device')
                ->url(fn (): string => DeviceResource::getUrl('view', ['record' => $this->getRecord()])),
        ];
    }
}
