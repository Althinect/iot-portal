<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\Entities\Pages;

use App\Filament\Portal\Resources\Entities\EntityResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListEntities extends ListRecords
{
    protected static string $resource = EntityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
