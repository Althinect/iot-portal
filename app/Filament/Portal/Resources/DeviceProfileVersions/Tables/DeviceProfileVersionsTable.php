<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\DeviceProfileVersions\Tables;

use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Services\DeviceProfileVersionLifecycleService;
use Filament\Actions;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class DeviceProfileVersionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('profile')->withCount('channels'))
            ->columns([
                TextColumn::make('profile.name')
                    ->label('Profile')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('version')
                    ->formatStateUsing(fn (mixed $state): string => is_numeric($state) ? "v{$state}" : '—')
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
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                Actions\Action::make('activate')
                    ->label('Activate')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (DeviceProfileVersion $record): bool => Gate::allows('activate', $record))
                    ->action(function (DeviceProfileVersion $record): void {
                        Gate::authorize('activate', $record);
                        app(DeviceProfileVersionLifecycleService::class)->activate($record);
                    }),
                Actions\Action::make('cloneAsDraft')
                    ->label('Clone as draft')
                    ->icon('heroicon-o-square-2-stack')
                    ->visible(fn (DeviceProfileVersion $record): bool => self::canClone($record))
                    ->action(function (DeviceProfileVersion $record): void {
                        Gate::authorize('create', DeviceProfileVersion::class);
                        abort_unless(self::canClone($record), 403);
                        app(DeviceProfileVersionLifecycleService::class)->cloneAsDraft($record);
                    }),
                Actions\ViewAction::make(),
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ])
            ->defaultSort('version', 'desc');
    }

    private static function canClone(DeviceProfileVersion $record): bool
    {
        $record->loadMissing('profile');

        return ! $record->isDraft()
            && $record->profile !== null
            && $record->profile->organization_id !== null
            && Gate::allows('create', DeviceProfileVersion::class)
            && Gate::allows('update', $record->profile);
    }
}
