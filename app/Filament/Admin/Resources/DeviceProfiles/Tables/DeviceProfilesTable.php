<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DeviceProfiles\Tables;

use App\Domain\DeviceProfile\Models\DeviceProfile;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Filament\Admin\Resources\DeviceProfileVersions\DeviceProfileVersionResource;
use Filament\Actions;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DeviceProfilesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['organization', 'activeVersion'])
                ->withCount(['versions', 'channels', 'devices']))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (DeviceProfile $record): string => $record->key),
                TextColumn::make('organization.name')
                    ->label('Scope')
                    ->state(fn (DeviceProfile $record): string => $record->organization?->name ?? 'Global')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Global' ? 'gray' : 'info')
                    ->sortable(),
                TextColumn::make('activeVersion.version')
                    ->label('Active')
                    ->state(fn (DeviceProfile $record): string => $record->activeVersion instanceof DeviceProfileVersion ? "v{$record->activeVersion->version}" : 'None')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'None' ? 'warning' : 'success')
                    ->url(fn (DeviceProfile $record): ?string => $record->activeVersion instanceof DeviceProfileVersion
                        ? DeviceProfileVersionResource::getUrl('edit', ['record' => $record->activeVersion])
                        : null),
                TextColumn::make('versions_count')
                    ->label('Versions')
                    ->sortable(),
                TextColumn::make('channels_count')
                    ->label('Channels')
                    ->sortable(),
                TextColumn::make('devices_count')
                    ->label('Devices')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\TrashedFilter::make(),
            ])
            ->recordActions([
                Actions\ViewAction::make(),
                Actions\EditAction::make(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                    Actions\ForceDeleteBulkAction::make(),
                    Actions\RestoreBulkAction::make(),
                ]),
            ])
            ->defaultSort('updated_at', 'desc');
    }
}
