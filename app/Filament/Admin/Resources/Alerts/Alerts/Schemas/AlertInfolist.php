<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Alerts\Alerts\Schemas;

use App\Domain\Alerts\Models\Alert;
use App\Filament\Admin\Resources\AutomationThresholdPolicies\AutomationThresholdPolicyResource;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AlertInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Alert')
                    ->schema([
                        TextEntry::make('status')
                            ->state(fn (Alert $record): string => $record->statusLabel())
                            ->badge()
                            ->color(fn (Alert $record): string => $record->statusColor()),
                        TextEntry::make('organization.name')
                            ->label('Organization'),
                        TextEntry::make('thresholdPolicy.name')
                            ->label('Threshold policy')
                            ->url(function (Alert $record): ?string {
                                if ($record->thresholdPolicy === null) {
                                    return null;
                                }

                                return AutomationThresholdPolicyResource::getUrl('edit', ['record' => $record->thresholdPolicy]);
                            }),
                        TextEntry::make('device.name')
                            ->label('Device')
                            ->placeholder('—'),
                        TextEntry::make('parameter_key')
                            ->label('Parameter')
                            ->placeholder('—'),
                        TextEntry::make('deviceChannel.label')
                            ->label('Channel')
                            ->placeholder('—'),
                        TextEntry::make('duration')
                            ->state(fn (Alert $record): string => $record->durationLabel()),
                    ])
                    ->columns(2),
                Section::make('Timeline')
                    ->schema([
                        TextEntry::make('alerted_at')
                            ->dateTime(),
                        TextEntry::make('normalized_at')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('alert_notification_sent_at')
                            ->label('Alert notification sent at')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('normalized_notification_sent_at')
                            ->label('Normalized notification sent at')
                            ->dateTime()
                            ->placeholder('—'),
                    ])
                    ->columns(2),
                Section::make('Telemetry')
                    ->schema([
                        TextEntry::make('alerted_telemetry_log_id')
                            ->label('Alert telemetry log')
                            ->placeholder('—'),
                        TextEntry::make('normalized_telemetry_log_id')
                            ->label('Normalized telemetry log')
                            ->placeholder('—'),
                    ])
                    ->columns(2),
            ]);
    }
}
