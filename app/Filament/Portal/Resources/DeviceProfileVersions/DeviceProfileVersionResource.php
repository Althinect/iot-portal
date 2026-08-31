<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\DeviceProfileVersions;

use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DeviceProfileVersionResource extends Resource
{
    protected static ?string $model = DeviceProfileVersion::class;

    protected static ?string $slug = 'device-profile-versions';

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static bool $shouldRegisterNavigation = false;

    public static function form(Schema $schema): Schema
    {
        return Schemas\DeviceProfileVersionForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return Schemas\DeviceProfileVersionInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return Tables\DeviceProfileVersionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ChannelsRelationManager::class,
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $tenantId = Filament::getTenant()?->getKey();

        if (! is_numeric($tenantId)) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return parent::getEloquentQuery()
            ->with('profile')
            ->where(function (Builder $query) use ($tenantId): void {
                $query
                    ->where(function (Builder $globalQuery): void {
                        $globalQuery
                            ->where('status', DeviceProfileVersion::STATUS_ACTIVE)
                            ->whereHas('profile', fn (Builder $profileQuery): Builder => $profileQuery->whereNull('organization_id'));
                    })
                    ->orWhereHas(
                        'profile',
                        fn (Builder $profileQuery): Builder => $profileQuery->where('organization_id', (int) $tenantId),
                    );
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDeviceProfileVersions::route('/'),
            'create' => Pages\CreateDeviceProfileVersion::route('/create'),
            'view' => Pages\ViewDeviceProfileVersion::route('/{record}'),
            'edit' => Pages\EditDeviceProfileVersion::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return static::getEloquentQuery();
    }
}
