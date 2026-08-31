<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\IoTDashboards;

use App\Domain\IoTDashboard\Models\IoTDashboard;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class IoTDashboardResource extends Resource
{
    protected static ?string $model = IoTDashboard::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static ?int $navigationSort = 7;

    public static function getNavigationGroup(): ?string
    {
        return __('IoT');
    }

    public static function getNavigationLabel(): string
    {
        return __('Dashboards');
    }

    public static function form(Schema $schema): Schema
    {
        return Schemas\IoTDashboardForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return Schemas\IoTDashboardInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return Tables\IoTDashboardsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIoTDashboards::route('/'),
            'create' => Pages\CreateIoTDashboard::route('/create'),
            'view' => Pages\ViewIoTDashboard::route('/{record}'),
            'edit' => Pages\EditIoTDashboard::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
