<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\Entities\Pages;

use App\Domain\Shared\Models\Organization;
use App\Filament\Portal\Resources\Entities\EntityResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateEntity extends CreateRecord
{
    protected static string $resource = EntityResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $organization = Filament::getTenant();
        abort_unless($organization instanceof Organization, 404);
        $data['organization_id'] = $organization->id;

        return $data;
    }
}
