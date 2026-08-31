<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\Alerts;

use App\Domain\Alerts\Models\Alert;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AlertResource extends Resource
{
    protected static ?string $model = Alert::class;

    protected static ?string $tenantOwnershipRelationshipName = 'organization';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static ?int $navigationSort = 5;

    public static function getNavigationGroup(): ?string
    {
        return 'Alerts & Automation';
    }

    public static function getNavigationLabel(): string
    {
        return 'Alerts';
    }

    public static function infolist(Schema $schema): Schema
    {
        return Schemas\AlertInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return Tables\AlertsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAlerts::route('/'),
            'view' => Pages\ViewAlert::route('/{record}'),
        ];
    }
}
