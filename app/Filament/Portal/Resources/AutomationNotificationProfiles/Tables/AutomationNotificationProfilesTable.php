<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\AutomationNotificationProfiles\Tables;

use Filament\Actions;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class AutomationNotificationProfilesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('channel')->badge(),
                IconColumn::make('enabled')->boolean(),
                TextColumn::make('recipient_count')
                    ->state(fn ($record): int => $record->recipientCount())
                    ->label('Recipients'),
                TextColumn::make('updated_at')->since()->sortable(),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                Actions\ViewAction::make(),
                Actions\EditAction::make(),
                Actions\DeleteAction::make()->label('Archive'),
                Actions\RestoreAction::make(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make()->label('Archive selected'),
                    Actions\RestoreBulkAction::make(),
                ]),
            ]);
    }
}
