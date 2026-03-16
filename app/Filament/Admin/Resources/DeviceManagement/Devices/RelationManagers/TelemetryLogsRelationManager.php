<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DeviceManagement\Devices\RelationManagers;

use App\Filament\Admin\Resources\Telemetry\DeviceTelemetryLogs\Tables\DeviceTelemetryLogsTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class TelemetryLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'telemetryLogs';

    public function form(Schema $schema): Schema
    {
        return $schema; // Read-only
    }

    public function table(Table $table): Table
    {
        return DeviceTelemetryLogsTable::configure(
            $table,
            showDeviceContext: false,
            useValuesModal: true,
        );
    }
}
