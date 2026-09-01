<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\DeviceManagement\Devices\Pages;

use App\Domain\DeviceManagement\Models\Device;
use App\Filament\Portal\Resources\DeviceManagement\Devices\DeviceResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

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
            EditAction::make(),
            Action::make('credentials')
                ->label('Credentials')
                ->icon(Heroicon::OutlinedKey)
                ->url(fn (): string => DeviceResource::getUrl('credentials', ['record' => $this->record]))
                ->visible(fn (): bool => $this->record instanceof Device
                    && Gate::allows('manageCredentials', $this->record)),
            Action::make('controlDashboard')
                ->label('Setup, Test & Control')
                ->icon(Heroicon::OutlinedCommandLine)
                ->url(fn (): string => DeviceResource::getUrl('control-dashboard', ['record' => $this->record]))
                ->visible(fn (): bool => $this->record instanceof Device
                    && ($this->record->canBeControlled() || $this->record->canBeSimulated())
                    && Gate::allows('control', $this->record)),
        ];
    }
}
