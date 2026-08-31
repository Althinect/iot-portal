<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\DeviceProfiles;

use App\Domain\DeviceProfile\Models\DeviceProfile;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class DeviceProfileResource extends Resource
{
    protected static ?string $model = DeviceProfile::class;

    protected static ?string $slug = 'device-profiles';

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return 'IoT Management';
    }

    public static function getNavigationLabel(): string
    {
        return 'Device Profiles';
    }

    public static function form(Schema $schema): Schema
    {
        return Schemas\DeviceProfileForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return Schemas\DeviceProfileInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return Tables\DeviceProfilesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\VersionsRelationManager::class,
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $tenantId = Filament::getTenant()?->getKey();

        if (! is_numeric($tenantId)) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return parent::getEloquentQuery()
            ->where(function (Builder $query) use ($tenantId): void {
                $query
                    ->whereNull('organization_id')
                    ->orWhere('organization_id', (int) $tenantId);
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDeviceProfiles::route('/'),
            'create' => Pages\CreateDeviceProfile::route('/create'),
            'view' => Pages\ViewDeviceProfile::route('/{record}'),
            'edit' => Pages\EditDeviceProfile::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        $tenantId = Filament::getTenant()?->getKey();

        if (! is_numeric($tenantId)) {
            return parent::getRecordRouteBindingEloquentQuery()->whereRaw('1 = 0');
        }

        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->where(function (Builder $query) use ($tenantId): void {
                $query
                    ->where(function (Builder $globalQuery): void {
                        $globalQuery
                            ->whereNull('organization_id')
                            ->whereNull('deleted_at');
                    })
                    ->orWhere('organization_id', (int) $tenantId);
            });
    }
}
