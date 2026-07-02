<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DeviceProfileVersions\Pages;

use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Filament\Admin\Resources\DeviceProfileVersions\DeviceProfileVersionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDeviceProfileVersion extends CreateRecord
{
    protected static string $resource = DeviceProfileVersionResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = DeviceProfileVersion::STATUS_DRAFT;

        if (! is_numeric($data['version'] ?? null) && is_numeric($data['device_profile_id'] ?? null)) {
            $data['version'] = DeviceProfileVersionResource::nextVersionNumber((int) $data['device_profile_id']);
        }

        return DeviceProfileVersionResource::prepareFormDataForSave($data);
    }
}
