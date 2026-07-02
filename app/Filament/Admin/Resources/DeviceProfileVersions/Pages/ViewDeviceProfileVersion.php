<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DeviceProfileVersions\Pages;

use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Services\DeviceProfileVersionLifecycleService;
use App\Filament\Admin\Resources\DeviceProfileVersions\DeviceProfileVersionResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewDeviceProfileVersion extends ViewRecord
{
    protected static string $resource = DeviceProfileVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('activate')
                ->label('Activate')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (DeviceProfileVersion $record): bool => $record->isDraft())
                ->action(fn (DeviceProfileVersion $record): DeviceProfileVersion => app(DeviceProfileVersionLifecycleService::class)->activate($record)),
            Actions\EditAction::make(),
        ];
    }
}
