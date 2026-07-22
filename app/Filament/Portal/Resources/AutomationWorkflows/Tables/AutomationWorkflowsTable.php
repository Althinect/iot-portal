<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\AutomationWorkflows\Tables;

use App\Domain\Automation\Enums\AutomationWorkflowStatus;
use App\Domain\Automation\Models\AutomationWorkflow;
use App\Filament\Portal\Resources\AutomationWorkflows\AutomationWorkflowResource;
use Filament\Actions;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class AutomationWorkflowsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (AutomationWorkflow $record): string => $record->slug),

                TextColumn::make('management')
                    ->label('Type')
                    ->badge()
                    ->state(fn (AutomationWorkflow $record): string => $record->is_managed ? 'Managed' : 'Manual')
                    ->color(fn (AutomationWorkflow $record): string => $record->is_managed ? 'gray' : 'info'),

                TextColumn::make('activeVersion.version')
                    ->label('Active Version')
                    ->formatStateUsing(fn (mixed $state): string => is_scalar($state) ? "v{$state}" : '—')
                    ->sortable(),

                SelectColumn::make('status')
                    ->options(self::statusOptions())
                    ->disabled(fn (AutomationWorkflow $record): bool => $record->is_managed),

                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(self::statusOptions()),
            ])
            ->recordUrl(fn (AutomationWorkflow $record): string => $record->is_managed
                ? AutomationWorkflowResource::getUrl('view', ['record' => $record])
                : AutomationWorkflowResource::getUrl('dag-editor', ['record' => $record]))
            ->recordActions([
                Actions\ActionGroup::make([
                    Actions\Action::make('dagEditor')
                        ->label('DAG Editor')
                        ->icon(Heroicon::OutlinedSquare3Stack3d)
                        ->url(fn (AutomationWorkflow $record): string => AutomationWorkflowResource::getUrl('dag-editor', ['record' => $record]))
                        ->visible(fn (AutomationWorkflow $record): bool => ! $record->is_managed),
                    Actions\ViewAction::make(),
                    Actions\EditAction::make()
                        ->visible(fn (AutomationWorkflow $record): bool => ! $record->is_managed),
                    Actions\DeleteAction::make()
                        ->visible(fn (AutomationWorkflow $record): bool => ! $record->is_managed),
                ])
                    ->label('Actions')
                    ->icon(Heroicon::OutlinedEllipsisVertical),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        $options = [];

        foreach (AutomationWorkflowStatus::cases() as $status) {
            $options[$status->value] = Str::headline($status->name);
        }

        return $options;
    }
}
