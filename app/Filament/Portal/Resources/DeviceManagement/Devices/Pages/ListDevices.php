<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\DeviceManagement\Devices\Pages;

use App\Filament\Portal\Resources\DeviceManagement\Devices\DeviceResource;
use Filament\Resources\Pages\ListRecords;
use Livewire\Attributes\On;

class ListDevices extends ListRecords
{
    protected static string $resource = DeviceResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * @return array<string>
     */
    protected function getListeners(): array
    {
        return [
            ...parent::getListeners(),
            'echo:devices,device.connection.changed' => 'refreshDeviceTable',
        ];
    }

    #[On('echo:devices,device.connection.changed')]
    public function refreshDeviceTable(): void
    {
        $this->resetTable();
    }
}
