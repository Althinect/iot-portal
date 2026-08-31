<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\Entities;

use App\Domain\Shared\Models\Entity;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class EntityResource extends Resource
{
    protected static ?string $model = Entity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $tenantOwnershipRelationshipName = 'organization';

    protected static ?string $navigationLabel = 'Sites';

    protected static ?string $modelLabel = 'site';

    protected static ?string $pluralModelLabel = 'sites';

    protected static ?int $navigationSort = 4;

    public static function getNavigationGroup(): ?string
    {
        return 'Organization';
    }

    public static function form(Schema $schema): Schema
    {
        return Schemas\EntityForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return Tables\EntitiesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $tenant = Filament::getTenant();

        return parent::getEloquentQuery()
            ->when(
                $tenant !== null,
                fn (Builder $query): Builder => $query->where('organization_id', $tenant->getKey()),
                fn (Builder $query): Builder => $query->whereRaw('1 = 0'),
            );
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEntities::route('/'),
            'create' => Pages\CreateEntity::route('/create'),
            'edit' => Pages\EditEntity::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return static::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
