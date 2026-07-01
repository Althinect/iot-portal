<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Alerts\Alerts\Tables;

use App\Domain\Alerts\Models\Alert;
use App\Domain\DeviceManagement\Models\Device;
use App\Filament\Admin\Resources\AutomationThresholdPolicies\AutomationThresholdPolicyResource;
use Filament\Actions;
use Filament\Forms\Components\DateTimePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AlertsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('thresholdPolicy.name')
                    ->label('Threshold policy')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (Alert $record): string => $record->device instanceof Device ? $record->device->name : 'No device'),
                TextColumn::make('organization.name')
                    ->label('Organization')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('parameter_key')
                    ->label('Parameter')
                    ->description(fn (Alert $record): ?string => $record->deviceChannel?->label)
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('status')
                    ->state(fn (Alert $record): string => $record->statusLabel())
                    ->badge()
                    ->color(fn (Alert $record): string => $record->statusColor()),
                TextColumn::make('alerted_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('normalized_at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('duration')
                    ->state(fn (Alert $record): string => $record->durationLabel())
                    ->toggleable(),
                TextColumn::make('alert_notification_sent_at')
                    ->label('Alert notification sent')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('normalized_notification_sent_at')
                    ->label('Normalized notification sent')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('organization')
                    ->relationship('organization', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('thresholdPolicy')
                    ->label('Threshold policy')
                    ->relationship('thresholdPolicy', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('device')
                    ->relationship('device', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('status')
                    ->options([
                        'open' => 'Open',
                        'normalized' => 'Normalized',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        return match ($value) {
                            'open' => $query->whereNull('normalized_at'),
                            'normalized' => $query->whereNotNull('normalized_at'),
                            default => $query,
                        };
                    }),
                Filter::make('alerted_at')
                    ->form([
                        DateTimePicker::make('from')
                            ->label('Alerted from'),
                        DateTimePicker::make('until')
                            ->label('Alerted until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $query->when(
                            $data['from'] ?? null,
                            fn (Builder $query, mixed $date): Builder => $query->where('alerted_at', '>=', $date),
                        );

                        return $query->when(
                            $data['until'] ?? null,
                            fn (Builder $query, mixed $date): Builder => $query->where('alerted_at', '<=', $date),
                        );
                    }),
            ])
            ->recordActions([
                Actions\Action::make('thresholdPolicy')
                    ->label('Threshold Policy')
                    ->url(function (Alert $record): ?string {
                        if ($record->thresholdPolicy === null) {
                            return null;
                        }

                        return AutomationThresholdPolicyResource::getUrl('edit', ['record' => $record->thresholdPolicy]);
                    })
                    ->visible(fn (Alert $record): bool => $record->thresholdPolicy !== null),
                Actions\ViewAction::make(),
            ])
            ->defaultSort('alerted_at', 'desc');
    }
}
