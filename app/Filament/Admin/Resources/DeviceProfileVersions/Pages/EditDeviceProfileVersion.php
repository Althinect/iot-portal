<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DeviceProfileVersions\Pages;

use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Services\DeviceProfileVersionLifecycleService;
use App\Filament\Admin\Resources\DeviceProfileVersions\DeviceProfileVersionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDeviceProfileVersion extends EditRecord
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
                ->action(function (DeviceProfileVersion $record): void {
                    app(DeviceProfileVersionLifecycleService::class)->activate($record);
                    $this->refreshFormData(['status']);
                }),
            Actions\Action::make('cloneAsDraft')
                ->label('Clone as draft')
                ->icon('heroicon-o-square-2-stack')
                ->visible(fn (DeviceProfileVersion $record): bool => ! $record->isDraft())
                ->action(function (DeviceProfileVersion $record): void {
                    $draft = app(DeviceProfileVersionLifecycleService::class)->cloneAsDraft($record);

                    $this->redirect(DeviceProfileVersionResource::getUrl('edit', ['record' => $draft]));
                }),
            Actions\ViewAction::make(),
            Actions\DeleteAction::make()
                ->visible(fn (DeviceProfileVersion $record): bool => $record->isDraft()),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->record instanceof DeviceProfileVersion
            ? DeviceProfileVersionResource::prepareFormDataForFill($this->record, $data)
            : $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return DeviceProfileVersionResource::prepareFormDataForSave(
            $data,
            $this->record instanceof DeviceProfileVersion ? $this->record : null,
        );
    }
}
