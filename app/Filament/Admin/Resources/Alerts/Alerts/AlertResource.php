<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Alerts\Alerts;

use App\Domain\Alerts\Models\Alert;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AlertResource extends Resource
{
    protected static ?string $model = Alert::class;

    protected static ?string $slug = 'alerts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?int $navigationSort = 8;

    protected static ?string $recordTitleAttribute = 'id';

    public static function getNavigationGroup(): ?string
    {
        return __('Alerts');
    }

    public static function getNavigationLabel(): string
    {
        return __('Alerts');
    }

    public static function infolist(Schema $schema): Schema
    {
        return Schemas\AlertInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return Tables\AlertsTable::configure($table)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'organization',
                'thresholdPolicy',
                'device',
                'deviceChannel',
            ]));
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
            'index' => Pages\ListAlerts::route('/'),
            'view' => Pages\ViewAlert::route('/{record}'),
        ];
    }
}
