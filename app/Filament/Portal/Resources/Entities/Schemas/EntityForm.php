<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\Entities\Schemas;

use App\Domain\Shared\Models\Entity;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class EntityForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Site details')
                ->description('Use parent sites to represent a building, floor, zone, or other hierarchy.')
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255),
                    Select::make('parent_id')
                        ->label('Parent site')
                        ->options(function (?Entity $record): array {
                            $tenant = Filament::getTenant();

                            return Entity::query()
                                ->when(
                                    $tenant !== null,
                                    fn (Builder $query): Builder => $query->where('organization_id', $tenant->getKey()),
                                    fn (Builder $query): Builder => $query->whereRaw('1 = 0'),
                                )
                                ->when($record !== null, fn (Builder $query): Builder => $query->whereKeyNot($record->id))
                                ->orderBy('label')
                                ->pluck('label', 'id')
                                ->all();
                        })
                        ->searchable()
                        ->preload(),
                    TextInput::make('icon')
                        ->helperText('Optional icon name used by dashboards and navigation.')
                        ->maxLength(255),
                ]),
        ]);
    }
}
