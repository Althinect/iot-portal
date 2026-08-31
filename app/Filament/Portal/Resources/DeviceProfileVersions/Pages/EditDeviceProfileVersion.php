<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\DeviceProfileVersions\Pages;

use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Services\DeviceProfileVersionLifecycleService;
use App\Filament\Portal\Resources\DeviceProfileVersions\DeviceProfileVersionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

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
                ->visible(fn (DeviceProfileVersion $record): bool => Gate::allows('activate', $record))
                ->action(function (DeviceProfileVersion $record): void {
                    Gate::authorize('activate', $record);
                    app(DeviceProfileVersionLifecycleService::class)->activate($record);
                    $this->redirect(DeviceProfileVersionResource::getUrl('view', ['record' => $record]));
                }),
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data = Arr::only($data, ['device_profile_id', 'protocol', 'notes']);
        $data['protocol'] = $this->record instanceof DeviceProfileVersion
            ? $this->record->protocol->value
            : $data['protocol'] ?? null;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return Arr::only($data, ['protocol', 'notes']);
    }
}
