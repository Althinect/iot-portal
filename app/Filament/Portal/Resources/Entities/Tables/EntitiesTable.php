<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\Entities\Tables;

use Filament\Actions;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class EntitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')
                    ->label('Site path')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('parent.label')
                    ->label('Parent')
                    ->placeholder('Top level'),
                TextColumn::make('children_count')
                    ->counts('children')
                    ->label('Child sites'),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
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
