<?php

namespace App\Filament\Admin\Resources\Authorization\Roles\RelationManagers;

use App\Filament\Admin\Resources\Authorization\Permissions\Tables\PermissionTable;
use Filament\Actions\Action;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Livewire\Attributes\Url;

class PermissionsRelation extends RelationManager
{
    protected static string $relationship = 'permissions';

    #[Url]
    public string $viewType = 'grid';

    public function updatedViewType(): void
    {
        $this->resetTable();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                // No form needed as we're attaching existing permissions
            ]);
    }

    public function table(Table $table): Table
    {
        // Use the existing PermissionTable configuration
        return PermissionTable::configure($table, $this->viewType)
            ->recordTitleAttribute('name')
            ->headerActions([
                AttachAction::make()
                    ->preloadRecordSelect()
                    ->recordSelectSearchColumns(['name', 'group'])
                    ->label(__('Attach Permission')),
                Action::make('toggle_view')
                    ->label(fn () => $this->viewType === 'grid' ? __('Table View') : __('Grid View'))
                    ->icon(fn () => $this->viewType === 'grid' ? 'heroicon-o-table-cells' : 'heroicon-o-squares-2x2')
                    ->action(function (): void {
                        $this->viewType = $this->viewType === 'grid' ? 'table' : 'grid';

                        $this->resetTable();
                    }),
            ])
            ->recordActions([
                DetachAction::make()
                    ->label(__('Remove')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make()
                        ->label(__('Remove Selected')),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
