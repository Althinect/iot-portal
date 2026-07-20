<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\IoTDashboards\Schemas;

use App\Domain\IoTDashboard\Enums\DashboardHistoryPreset;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class IoTDashboardInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Dashboard')
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('organization.name')
                            ->label('Organization'),
                        TextEntry::make('slug')
                            ->copyable(),
                        TextEntry::make('widgets_count')
                            ->label('Widgets')
                            ->state(fn ($record): int => $record->widgets()->count()),
                        TextEntry::make('refresh_interval_seconds')
                            ->label('Refresh interval')
                            ->suffix(' seconds'),
                        TextEntry::make('default_history_preset')
                            ->label('Default history range')
                            ->formatStateUsing(function (mixed $state): string {
                                if ($state instanceof DashboardHistoryPreset) {
                                    return $state->getLabel();
                                }

                                if (is_string($state)) {
                                    return DashboardHistoryPreset::tryFrom($state)?->getLabel() ?? $state;
                                }

                                return DashboardHistoryPreset::Last6Hours->getLabel();
                            }),
                        IconEntry::make('is_active')
                            ->label('Active')
                            ->boolean(),
                        TextEntry::make('description')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}
