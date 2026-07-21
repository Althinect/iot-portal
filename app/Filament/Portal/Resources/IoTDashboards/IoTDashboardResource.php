<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\IoTDashboards;

use App\Domain\IoTDashboard\Models\IoTDashboard;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class IoTDashboardResource extends Resource
{
    protected static ?string $model = IoTDashboard::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Dashboard';

    protected static ?string $pluralModelLabel = 'Dashboards';

    protected static ?string $tenantOwnershipRelationshipName = 'organization';

    public static function getNavigationGroup(): ?string
    {
        return __('IoT Management');
    }

    public static function getNavigationLabel(): string
    {
        return __('Dashboards');
    }

    public static function infolist(Schema $schema): Schema
    {
        return Schemas\IoTDashboardInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return Tables\IoTDashboardsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIoTDashboards::route('/'),
            'view' => Pages\ViewIoTDashboard::route('/{record}'),
        ];
    }
}
