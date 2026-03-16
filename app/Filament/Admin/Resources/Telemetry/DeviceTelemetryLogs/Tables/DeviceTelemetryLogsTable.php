<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Telemetry\DeviceTelemetryLogs\Tables;

use App\Domain\Telemetry\Enums\ValidationStatus;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Infolists\Components\CodeEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Phiki\Grammar\Grammar;

class DeviceTelemetryLogsTable
{
    public static function configure(
        Table $table,
        bool $showDeviceContext = true,
        bool $useValuesModal = false,
    ): Table {
        $columns = [
            ...($showDeviceContext ? [
                TextColumn::make('device.uuid')
                    ->label('Device UUID')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('device.name')
                    ->label('Device Name')
                    ->searchable(),
            ] : []),
            TextColumn::make('schemaVersion.version')
                ->label('Schema Version')
                ->sortable(),

            TextColumn::make('validation_status')
                ->badge()
                ->formatStateUsing(fn (ValidationStatus|string $state): string => $state instanceof ValidationStatus ? $state->label() : (string) $state),

            TextColumn::make('processing_state')
                ->badge()
                ->sortable(),

            TextColumn::make('recorded_at')
                ->dateTime()
                ->sortable(),

            TextColumn::make('received_at')
                ->dateTime()
                ->sortable(),
        ];

        return $table
            ->columns($columns)
            ->filters([
                SelectFilter::make('validation_status')
                    ->label('Validation Status')
                    ->options(ValidationStatus::class),

                SelectFilter::make('processing_state')
                    ->options([
                        'processed' => 'Processed',
                        'publish_failed' => 'Publish Failed',
                        'invalid' => 'Invalid',
                        'inactive_skipped' => 'Inactive Skipped',
                    ]),

                Filter::make('recorded_at')
                    ->form([
                        DateTimePicker::make('from')
                            ->label('From'),
                        DateTimePicker::make('until')
                            ->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $query->when(
                            $data['from'] ?? null,
                            fn (Builder $query, $date): Builder => $query->where('recorded_at', '>=', $date),
                        );

                        return $query->when(
                            $data['until'] ?? null,
                            fn (Builder $query, $date): Builder => $query->where('recorded_at', '<=', $date),
                        );
                    }),
            ])
            ->recordActions([
                $useValuesModal ? self::makeValuesViewAction() : ViewAction::make(),
            ])
            ->defaultSort('recorded_at', 'desc');
    }

    private static function makeValuesViewAction(): ViewAction
    {
        return ViewAction::make()
            ->label('View Values')
            ->modalHeading('Telemetry Values')
            ->modalWidth('4xl')
            ->schema([
                Section::make('Telemetry')
                    ->schema([
                        TextEntry::make('schemaVersion.version')
                            ->label('Schema Version'),
                        TextEntry::make('validation_status')
                            ->label('Validation Status')
                            ->badge()
                            ->formatStateUsing(fn (ValidationStatus|string $state): string => $state instanceof ValidationStatus ? $state->label() : (string) $state),
                        TextEntry::make('processing_state')
                            ->label('Processing State')
                            ->badge(),
                        TextEntry::make('recorded_at')
                            ->dateTime(),
                        TextEntry::make('received_at')
                            ->dateTime(),
                    ])
                    ->columns(3),
                Section::make('Values')
                    ->schema([
                        CodeEntry::make('transformed_values')
                            ->label('Values')
                            ->grammar(Grammar::Json)
                            ->copyable()
                            ->copyableState(fn (DeviceTelemetryLog $record): string => self::formatPayload(
                                $record->getAttribute('transformed_values') ?? self::resolveDisplayValues($record),
                            ) ?? '{}')
                            ->state(fn (DeviceTelemetryLog $record): ?string => self::formatPayload(
                                $record->getAttribute('transformed_values') ?? self::resolveDisplayValues($record),
                            ))
                            ->columnSpanFull()
                            ->placeholder('No values recorded.'),
                    ]),
                Section::make('Raw Payload')
                    ->schema([
                        CodeEntry::make('raw_payload')
                            ->label('Raw Payload')
                            ->grammar(Grammar::Json)
                            ->copyable()
                            ->copyableState(fn (DeviceTelemetryLog $record): string => self::formatPayload($record->getAttribute('raw_payload')) ?? '{}')
                            ->state(fn (DeviceTelemetryLog $record): ?string => self::formatPayload($record->getAttribute('raw_payload')))
                            ->columnSpanFull()
                            ->placeholder('{}'),
                    ]),
            ]);
    }

    private static function resolveDisplayValues(DeviceTelemetryLog $record): mixed
    {
        return $record->getAttribute('transformed_values')
            ?? $record->getAttribute('mutated_values')
            ?? $record->getAttribute('raw_payload');
    }

    private static function formatPayload(mixed $payload): ?string
    {
        if (is_array($payload)) {
            $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            return $encoded === false ? null : $encoded;
        }

        if (is_scalar($payload)) {
            return (string) $payload;
        }

        return null;
    }
}
