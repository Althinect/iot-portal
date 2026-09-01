<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\DeviceManagement\Devices\Pages;

use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\Shared\Models\Entity;
use App\Domain\Shared\Models\Organization;
use App\Filament\Portal\Resources\DeviceManagement\Devices\DeviceResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateDevice extends CreateRecord
{
    protected static string $resource = DeviceResource::class;

    public function getMaxContentWidth(): string
    {
        return 'full';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $organization = Filament::getTenant();
        abort_unless($organization instanceof Organization, 404);
        $data['organization_id'] = $organization->id;
        $this->validateTenantReferences($data, $organization);

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function validateTenantReferences(array $data, Organization $organization): void
    {
        $profileVersion = DeviceProfileVersion::query()
            ->whereKey($data['device_profile_version_id'] ?? null)
            ->where('status', DeviceProfileVersion::STATUS_ACTIVE)
            ->whereHas('profile', fn ($query) => $query
                ->whereNull('organization_id')
                ->orWhere('organization_id', $organization->id))
            ->first();

        if (! $profileVersion instanceof DeviceProfileVersion) {
            throw ValidationException::withMessages([
                'device_profile_version_id' => 'Select an active global or organization profile version.',
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
    }

    protected function getRedirectUrl(): string
    {
        return DeviceResource::getUrl('control-dashboard', ['record' => $this->record]);
    }
}
