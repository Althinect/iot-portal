<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DeviceManagement\Devices;

use App\Domain\DeviceManagement\Models\Device;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class DeviceResource extends Resource
{
    protected static ?string $model = Device::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return __('IoT Management');
    }

    public static function getNavigationLabel(): string
    {
        return __('Devices');
    }

    public static function form(Schema $schema): Schema
    {
        return Schemas\DeviceForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return Schemas\DeviceInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return Tables\DevicesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\SignalBindingsRelationManager::class,
            RelationManagers\TelemetryLogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDevices::route('/'),
            'create' => Pages\CreateDevice::route('/create'),
            'view' => Pages\ViewDevice::route('/{record}'),
            'edit' => Pages\EditDevice::route('/{record}/edit'),
            'control-dashboard' => Pages\DeviceControlDashboard::route('/{record}/control-dashboard'),
        ];
    }
}
