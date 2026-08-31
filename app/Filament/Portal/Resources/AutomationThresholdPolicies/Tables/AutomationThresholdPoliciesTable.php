<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\AutomationThresholdPolicies\Tables;

use App\Domain\Automation\Models\AutomationThresholdPolicy;
use Filament\Actions;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class AutomationThresholdPoliciesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('device.name')->label('Device')->searchable(),
                TextColumn::make('condition')
                    ->state(fn (AutomationThresholdPolicy $record): string => $record->conditionLabel()),
                IconColumn::make('is_active')->boolean(),
                TextColumn::make('notificationProfile.name')->label('Notification profile'),
                TextColumn::make('updated_at')->since()->sortable(),
            ])
            ->filters([TrashedFilter::make()])
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
