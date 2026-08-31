<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\Shared\Users\Pages;

use App\Filament\Portal\Resources\Shared\Users\UserResource;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;
}
