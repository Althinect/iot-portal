<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\Alerts\Schemas;

use App\Domain\Alerts\Models\Alert;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AlertInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Incident')
                ->schema([
                    TextEntry::make('status')
                        ->state(fn (Alert $record): string => $record->statusLabel())
                        ->badge()
                        ->color(fn (Alert $record): string => $record->statusColor()),
                    TextEntry::make('thresholdPolicy.name')->label('Policy'),
                    TextEntry::make('device.name')->label('Device'),
                    TextEntry::make('deviceChannel.label')->label('Channel'),
                    TextEntry::make('parameter_key')->label('Parameter'),
                    TextEntry::make('alerted_at')->dateTime(),
                    TextEntry::make('normalized_at')->dateTime()->placeholder('Still open'),
                    TextEntry::make('duration')->state(fn (Alert $record): string => $record->durationLabel()),
                ])
                ->columns(2),
            Section::make('Acknowledgement')
                ->schema([
                    TextEntry::make('acknowledged_at')->dateTime()->placeholder('Not acknowledged'),
                    TextEntry::make('acknowledgedBy.name')->label('Acknowledged by')->placeholder('—'),
                    TextEntry::make('acknowledgement_note')->label('Note')->placeholder('—')->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }
}
