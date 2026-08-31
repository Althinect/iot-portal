<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\IoTDashboards\Tables;

use App\Domain\IoTDashboard\Models\IoTDashboard;
use Filament\Actions;
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

                TextColumn::make('organization.name')
                    ->label('Organization')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('slug')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

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
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                Actions\Action::make('openDashboard')
                    ->label('Open Dashboard')
                    ->icon(Heroicon::OutlinedPresentationChartLine)
                    ->color('success')
                    ->url(
                        fn (IoTDashboard $record): string => route(
                            'filament.admin.pages.io-t-dashboard',
                            ['dashboard' => $record->id],
                        ),
                        shouldOpenInNewTab: true,
                    ),
                Actions\ViewAction::make(),
                Actions\EditAction::make(),
                Actions\DeleteAction::make()->label('Archive'),
                Actions\RestoreAction::make(),
                Actions\ForceDeleteAction::make(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make()->label('Archive selected'),
                    Actions\RestoreBulkAction::make(),
                    Actions\ForceDeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('updated_at', 'desc');
    }
}
