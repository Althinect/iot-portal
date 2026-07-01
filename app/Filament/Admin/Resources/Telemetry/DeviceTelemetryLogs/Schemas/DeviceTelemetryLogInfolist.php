<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Telemetry\DeviceTelemetryLogs\Schemas;

use App\Domain\Telemetry\Enums\ValidationStatus;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class DeviceTelemetryLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Device')
                    ->schema([
                        TextEntry::make('device.name')
                            ->label('Device Name')
                            ->weight('medium')
                            ->icon(Heroicon::OutlinedCpuChip),
                        TextEntry::make('device.uuid')
                            ->label('Device UUID')
                            ->icon(Heroicon::OutlinedIdentification),
                        TextEntry::make('profileVersion.version')
                            ->label('Profile Version')
                            ->icon(Heroicon::OutlinedDocumentText),
                        TextEntry::make('channel.label')
                            ->label('Channel')
                            ->icon(Heroicon::OutlinedDocumentText),
                    ])
                    ->columns(3),

                Section::make('Telemetry')
                    ->schema([
                        TextEntry::make('validation_status')
                            ->label('Validation Status')
                            ->badge()
                            ->formatStateUsing(fn (ValidationStatus|string $state): string => $state instanceof ValidationStatus ? $state->label() : (string) $state),
                        TextEntry::make('processing_state')
                            ->label('Processing State')
                            ->badge(),
                        TextEntry::make('recorded_at')
                            ->dateTime()
                            ->icon(Heroicon::OutlinedClock),
                        TextEntry::make('received_at')
                            ->dateTime()
                            ->icon(Heroicon::OutlinedClock),
                    ])
                    ->columns(3),

                Section::make('Payloads')
                    ->schema([
                        TextEntry::make('raw_payload')
                            ->label('Raw Payload')
                            ->formatStateUsing(fn (mixed $state): ?string => self::formatJson($state))
                            ->extraAttributes(['class' => 'font-mono whitespace-pre-wrap'])
                            ->columnSpanFull(),
                        TextEntry::make('validation_errors')
                            ->label('Validation Errors')
                            ->formatStateUsing(fn (mixed $state): ?string => self::formatJson($state))
                            ->extraAttributes(['class' => 'font-mono whitespace-pre-wrap'])
                            ->columnSpanFull(),
                        TextEntry::make('mutated_values')
                            ->label('Mutated Values')
                            ->formatStateUsing(fn (mixed $state): ?string => self::formatJson($state))
                            ->extraAttributes(['class' => 'font-mono whitespace-pre-wrap'])
                            ->columnSpanFull(),
                        TextEntry::make('transformed_values')
                            ->label('Transformed Values')
                            ->formatStateUsing(fn (mixed $state): ?string => self::formatJson($state))
                            ->extraAttributes(['class' => 'font-mono whitespace-pre-wrap'])
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    private static function formatJson(mixed $state): ?string
    {
        if (is_array($state)) {
            $encoded = json_encode($state, JSON_PRETTY_PRINT);

            return $encoded === false ? null : $encoded;
        }

        return is_string($state) ? $state : null;
    }
}
