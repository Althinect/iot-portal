<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\DeviceManagement\Devices\Pages;

use App\Domain\DeviceManagement\Models\Device;
use App\Filament\Portal\Resources\DeviceManagement\Devices\DeviceResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewDevice extends ViewRecord
{
    protected static string $resource = DeviceResource::class;

    public function getMaxContentWidth(): string
    {
        return 'full';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('controlDashboard')
                ->label('Control Dashboard')
                ->icon(Heroicon::OutlinedCommandLine)
                ->url(fn (): string => DeviceResource::getUrl('control-dashboard', ['record' => $this->record]))
                ->visible(fn (): bool => $this->record instanceof Device && $this->record->canBeControlled()),
        ];
    }
}
