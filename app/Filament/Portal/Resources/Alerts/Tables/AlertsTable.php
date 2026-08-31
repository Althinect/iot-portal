<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\Alerts\Tables;

use App\Domain\Alerts\Models\Alert;
use App\Domain\Shared\Models\User;
use Filament\Actions;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class AlertsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('status')
                    ->state(fn (Alert $record): string => $record->statusLabel())
                    ->badge()
                    ->color(fn (Alert $record): string => $record->statusColor()),
                TextColumn::make('device.name')->label('Device')->searchable(),
                TextColumn::make('thresholdPolicy.name')->label('Policy')->searchable(),
                TextColumn::make('parameter_key')->label('Parameter'),
                IconColumn::make('acknowledged_at')
                    ->label('Acknowledged')
                    ->state(fn (Alert $record): bool => $record->isAcknowledged())
                    ->boolean(),
                TextColumn::make('alerted_at')->dateTime()->sortable(),
                TextColumn::make('duration')->state(fn (Alert $record): string => $record->durationLabel()),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'open' => 'Open',
                        'normalized' => 'Normalized',
                    ])
                    ->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                        'open' => $query->whereNull('normalized_at'),
                        'normalized' => $query->whereNotNull('normalized_at'),
                        default => $query,
                    }),
            ])
            ->recordActions([
                self::acknowledgeAction(),
                Actions\ViewAction::make(),
            ])
            ->defaultSort('alerted_at', 'desc');
    }

    public static function acknowledgeAction(): Actions\Action
    {
        return Actions\Action::make('acknowledge')
            ->label('Acknowledge')
            ->icon('heroicon-o-check-circle')
            ->color('warning')
            ->schema([
                Textarea::make('note')
                    ->label('Acknowledgement note')
                    ->maxLength(2000)
                    ->rows(3),
            ])
            ->visible(fn (Alert $record): bool => ! $record->isAcknowledged() && Gate::allows('acknowledge', $record))
            ->action(function (array $data, Alert $record): void {
                Gate::authorize('acknowledge', $record);
                $user = auth()->user();
                abort_unless($user instanceof User, 403);

                $record->acknowledge($user, is_string($data['note'] ?? null) ? $data['note'] : null);
            });
    }
}
