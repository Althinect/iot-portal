<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\DeviceProfileVersions\Pages;

use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Services\DeviceProfileVersionLifecycleService;
use App\Filament\Portal\Resources\DeviceProfileVersions\DeviceProfileVersionResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;

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
                ->visible(fn (DeviceProfileVersion $record): bool => Gate::allows('activate', $record))
                ->action(function (DeviceProfileVersion $record): void {
                    Gate::authorize('activate', $record);
                    app(DeviceProfileVersionLifecycleService::class)->activate($record);
                }),
            Actions\Action::make('cloneAsDraft')
                ->label('Clone as draft')
                ->icon('heroicon-o-square-2-stack')
                ->visible(fn (DeviceProfileVersion $record): bool => $this->canClone($record))
                ->action(function (DeviceProfileVersion $record): void {
                    abort_unless($this->canClone($record), 403);
                    Gate::authorize('create', DeviceProfileVersion::class);
                    $draft = app(DeviceProfileVersionLifecycleService::class)->cloneAsDraft($record);
                    $this->redirect(DeviceProfileVersionResource::getUrl('edit', ['record' => $draft]));
                }),
            Actions\EditAction::make(),
        ];
    }

    private function canClone(DeviceProfileVersion $record): bool
    {
        $record->loadMissing('profile');

        return ! $record->isDraft()
            && $record->profile !== null
            && $record->profile->organization_id !== null
            && Gate::allows('create', DeviceProfileVersion::class)
            && Gate::allows('update', $record->profile);
    }
}
