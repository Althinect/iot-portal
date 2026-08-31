<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\DeviceProfiles\Pages;

use App\Domain\DeviceProfile\Enums\Protocol;
use App\Domain\DeviceProfile\Models\DeviceProfile;
use App\Domain\DeviceProfile\Services\DeviceProfileVersionLifecycleService;
use App\Domain\Shared\Models\Organization;
use App\Filament\Portal\Resources\DeviceProfiles\DeviceProfileResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CreateDeviceProfile extends CreateRecord
{
    protected static string $resource = DeviceProfileResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $organization = Filament::getTenant();
        abort_unless($organization instanceof Organization, 404);

        return DB::transaction(function () use ($data, $organization): DeviceProfile {
            $profile = DeviceProfile::query()->create([
                ...Arr::only($data, ['name', 'key', 'tags']),
                'organization_id' => $organization->getKey(),
            ]);

            app(DeviceProfileVersionLifecycleService::class)->createDraftForProfile($profile, [
                'protocol' => Protocol::Mqtt,
                'protocol_config' => null,
                'notes' => 'Initial tenant contract draft.',
            ]);

            return $profile;
        });
    }
}
