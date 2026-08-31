<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\IoTDashboards\Tables;

use App\Domain\IoTDashboard\Models\IoTDashboard;
use App\Filament\Portal\Pages\IoTDashboard as IoTDashboardPage;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
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
                TextColumn::make('entity.label')
                    ->label('Primary site')
                    ->placeholder('All sites')
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                TrashedFilter::make(),
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
                EditAction::make(),
                DeleteAction::make()->label('Archive'),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->label('Archive selected'),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->defaultSort('updated_at', 'desc');
    }
}
