<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\DeviceProfiles\Tables;

use App\Domain\DeviceProfile\Models\DeviceProfile;
use Filament\Actions;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DeviceProfilesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('activeVersion'))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('key')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('scope')
                    ->state(fn (DeviceProfile $record): string => $record->isGlobal() ? 'Platform' : 'Private')
                    ->badge()
                    ->color(fn (DeviceProfile $record): string => $record->isGlobal() ? 'info' : 'success'),
                TextColumn::make('activeVersion.version')
                    ->label('Active version')
                    ->formatStateUsing(fn (mixed $state): string => is_numeric($state) ? "v{$state}" : 'No active version')
                    ->placeholder('No active version'),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                Actions\ViewAction::make(),
                Actions\EditAction::make(),
                Actions\DeleteAction::make()->label('Archive'),
                Actions\RestoreAction::make(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make()->label('Archive selected'),
                    Actions\RestoreBulkAction::make(),
                ]),
            ]);
    }
}
