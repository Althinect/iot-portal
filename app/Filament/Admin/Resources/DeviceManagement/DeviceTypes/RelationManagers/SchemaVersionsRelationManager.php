<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DeviceManagement\DeviceTypes\RelationManagers;

use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Filament\Admin\Resources\DeviceSchema\DeviceSchemas\DeviceSchemaResource;
use App\Filament\Admin\Resources\DeviceSchema\DeviceSchemaVersions\DeviceSchemaVersionResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SchemaVersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'schemaVersions';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('version')
            ->columns([
                TextColumn::make('schema.name')
                    ->label('Device Schema')
                    ->searchable()
                    ->sortable()
                    ->url(fn (DeviceSchemaVersion $record): ?string => $record->device_schema_id
                        ? DeviceSchemaResource::getUrl('view', ['record' => $record->device_schema_id])
                        : null),

                TextColumn::make('version')
                    ->label('Schema Version')
                    ->sortable()
                    ->url(fn (DeviceSchemaVersion $record): ?string => $record->id
                        ? DeviceSchemaVersionResource::getUrl('view', ['record' => $record->id])
                        : null),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'draft' => 'warning',
                        'active' => 'success',
                        'archived' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('topics_count')
                    ->label('Topics')
                    ->counts('topics')
                    ->sortable(),

                TextColumn::make('parameters_count')
                    ->label('Parameters')
                    ->counts('parameters')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}