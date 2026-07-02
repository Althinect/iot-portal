<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DeviceProfiles;

use App\Domain\DeviceProfile\Models\DeviceProfile;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class DeviceProfileResource extends Resource
{
    protected static ?string $model = DeviceProfile::class;

    protected static ?string $slug = 'device-types-profiles';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return __('IoT Management');
    }

    public static function getNavigationLabel(): string
    {
        return __('Device Types & Profiles');
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
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
