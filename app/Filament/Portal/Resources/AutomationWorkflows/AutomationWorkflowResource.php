<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\AutomationWorkflows;

use App\Domain\Automation\Models\AutomationWorkflow;
use App\Domain\Shared\Models\Organization;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AutomationWorkflowResource extends Resource
{
    protected static ?string $model = AutomationWorkflow::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?int $navigationSort = 3;

    protected static ?string $tenantOwnershipRelationshipName = 'organization';

    public static function getNavigationGroup(): ?string
    {
        return __('Automation');
    }

    public static function getNavigationLabel(): string
    {
        return __('Automations');
    }

    public static function form(Schema $schema): Schema
    {
        return Schemas\AutomationWorkflowForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return Schemas\AutomationWorkflowInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return Tables\AutomationWorkflowsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Organization) {
            return $query->whereRaw('1 = 0');
        }

        $tenantId = $tenant->getKey();

        if (! is_numeric($tenantId)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('automation_workflows.organization_id', (int) $tenantId);
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof AutomationWorkflow
            && ! $record->is_managed
            && parent::canEdit($record);
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof AutomationWorkflow
            && ! $record->is_managed
            && parent::canDelete($record);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAutomationWorkflows::route('/'),
            'create' => Pages\CreateAutomationWorkflow::route('/create'),
            'view' => Pages\ViewAutomationWorkflow::route('/{record}'),
            'edit' => Pages\EditAutomationWorkflow::route('/{record}/edit'),
            'dag-editor' => Pages\EditAutomationDag::route('/{record}/dag-editor'),
        ];
    }
}
