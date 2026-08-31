<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\DeviceManagement\Devices\Pages;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Services\TenantDeviceLifecycleManager;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\Shared\Models\Entity;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use App\Filament\Portal\Resources\DeviceManagement\Devices\DeviceResource;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class EditDevice extends EditRecord
{
    protected static string $resource = DeviceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            Action::make('decommission')
                ->color('danger')
                ->icon(Heroicon::OutlinedArchiveBox)
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->record instanceof Device
                    && Gate::allows('decommission', $this->record))
                ->action(function (): void {
                    $user = Auth::user();
                    abort_unless($user instanceof User && $this->record instanceof Device, 403);
                    app(TenantDeviceLifecycleManager::class)->decommission($this->record, $user);
                    $this->redirect(DeviceResource::getUrl('index'));
                }),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $organization = Filament::getTenant();
        abort_unless($organization instanceof Organization, 404);

        $profileVersion = DeviceProfileVersion::query()
            ->whereKey($data['device_profile_version_id'] ?? null)
            ->whereHas('profile', fn ($query) => $query
                ->whereNull('organization_id')
                ->orWhere('organization_id', $organization->id))
            ->first();

        if (! $profileVersion instanceof DeviceProfileVersion) {
            throw ValidationException::withMessages([
                'device_profile_version_id' => 'Select a global or organization profile version.',
            ]);
        }

        $entityId = $data['entity_id'] ?? null;

        if ($entityId !== null && ! Entity::query()
            ->whereKey($entityId)
            ->where('organization_id', $organization->id)
            ->exists()) {
            throw ValidationException::withMessages([
                'entity_id' => 'Select a site from the active organization.',
            ]);
        }

        return $data;
    }
}
