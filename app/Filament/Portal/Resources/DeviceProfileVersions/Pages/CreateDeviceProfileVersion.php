<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\DeviceProfileVersions\Pages;

use App\Domain\DeviceProfile\Enums\Protocol;
use App\Domain\DeviceProfile\Services\DeviceProfileVersionLifecycleService;
use App\Filament\Portal\Resources\DeviceProfiles\DeviceProfileResource;
use App\Filament\Portal\Resources\DeviceProfileVersions\DeviceProfileVersionResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class CreateDeviceProfileVersion extends CreateRecord
{
    protected static string $resource = DeviceProfileVersionResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $profileId = $data['device_profile_id'] ?? null;
        abort_unless(is_numeric($profileId), 404);

        $profile = DeviceProfileResource::getEloquentQuery()
            ->whereNotNull('organization_id')
            ->findOrFail((int) $profileId);

        Gate::authorize('update', $profile);

        return app(DeviceProfileVersionLifecycleService::class)->createDraftForProfile($profile, [
            'protocol' => Protocol::tryFrom((string) ($data['protocol'] ?? '')) ?? Protocol::Mqtt,
            'protocol_config' => null,
            'notes' => is_string($data['notes'] ?? null) ? $data['notes'] : null,
        ]);
    }
}
