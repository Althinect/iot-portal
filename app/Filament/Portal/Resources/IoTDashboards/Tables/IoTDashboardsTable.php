<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\IoTDashboards\Tables;

use App\Domain\IoTDashboard\Models\IoTDashboard;
use App\Filament\Portal\Pages\IoTDashboard as IoTDashboardPage;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class IoTDashboardsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                TextColumn::make('widgets_count')
                    ->label('Widgets')
                    ->counts('widgets')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->since()
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('openDashboard')
                    ->label('Open Dashboard')
                    ->icon(Heroicon::OutlinedPresentationChartLine)
                    ->color('success')
                    ->url(fn (IoTDashboard $record): string => IoTDashboardPage::getUrl(
                        parameters: ['dashboard' => $record->getKey()],
                        panel: 'portal',
                        tenant: Filament::getTenant(),
                    )),
                ViewAction::make(),
            ])
            ->defaultSort('updated_at', 'desc');
    }
}
