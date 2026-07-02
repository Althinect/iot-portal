<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DeviceProfileVersions\Tables;

use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Services\DeviceProfileVersionLifecycleService;
use Filament\Actions;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DeviceProfileVersionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('profile')
                ->withCount(['channels', 'derivedParameters', 'devices']))
            ->columns([
                TextColumn::make('profile.name')
                    ->label('Profile')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('version')
                    ->formatStateUsing(fn (mixed $state): string => is_scalar($state) ? "v{$state}" : '—')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        DeviceProfileVersion::STATUS_ACTIVE => 'success',
                        DeviceProfileVersion::STATUS_SUPERSEDED => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('protocol')
                    ->badge(),
                TextColumn::make('channels_count')
                    ->label('Channels')
                    ->sortable(),
                TextColumn::make('derived_parameters_count')
                    ->label('Derived')
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
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        DeviceProfileVersion::STATUS_DRAFT => 'Draft',
                        DeviceProfileVersion::STATUS_ACTIVE => 'Active',
                        DeviceProfileVersion::STATUS_SUPERSEDED => 'Superseded',
                    ]),
            ])
            ->recordActions([
                Actions\Action::make('activate')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (DeviceProfileVersion $record): bool => $record->isDraft())
                    ->action(fn (DeviceProfileVersion $record): DeviceProfileVersion => app(DeviceProfileVersionLifecycleService::class)->activate($record)),
                Actions\Action::make('cloneAsDraft')
                    ->label('Clone')
                    ->icon('heroicon-o-square-2-stack')
                    ->visible(fn (DeviceProfileVersion $record): bool => ! $record->isDraft())
                    ->action(fn (DeviceProfileVersion $record): DeviceProfileVersion => app(DeviceProfileVersionLifecycleService::class)->cloneAsDraft($record)),
                Actions\ViewAction::make(),
                Actions\EditAction::make(),
                Actions\DeleteAction::make()
                    ->visible(fn (DeviceProfileVersion $record): bool => $record->isDraft()),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('version', 'desc');
    }
}
