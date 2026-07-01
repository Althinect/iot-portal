<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Telemetry\DeviceTelemetryLogs;

use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use App\Filament\Admin\Resources\Telemetry\DeviceTelemetryLogs\Pages\ListDeviceTelemetryLogs;
use App\Filament\Admin\Resources\Telemetry\DeviceTelemetryLogs\Pages\ViewDeviceTelemetryLog;
use App\Filament\Admin\Resources\Telemetry\DeviceTelemetryLogs\Schemas\DeviceTelemetryLogInfolist;
use App\Filament\Admin\Resources\Telemetry\DeviceTelemetryLogs\Tables\DeviceTelemetryLogsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class DeviceTelemetryLogResource extends Resource
{
    protected static ?string $model = DeviceTelemetryLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 4;

    public static function getNavigationGroup(): ?string
    {
        return __('IoT Management');
    }

    public static function getNavigationLabel(): string
    {
        return __('Telemetry Logs');
    }

    public static function infolist(Schema $schema): Schema
    {
        return DeviceTelemetryLogInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DeviceTelemetryLogsTable::configure($table)
            ->modifyQueryUsing(fn ($query) => $query->with(['device', 'profileVersion', 'channel']));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDeviceTelemetryLogs::route('/'),
            'view' => ViewDeviceTelemetryLog::route('/{record}'),
        ];
    }
}
