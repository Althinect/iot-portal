<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\AutomationThresholdPolicies;

use App\Domain\Automation\Models\AutomationThresholdPolicy;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class AutomationThresholdPolicyResource extends Resource
{
    protected static ?string $model = AutomationThresholdPolicy::class;

    protected static ?string $slug = 'threshold-policies';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $tenantOwnershipRelationshipName = 'organization';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?int $navigationSort = 6;

    public static function getNavigationGroup(): ?string
    {
        return 'Alerts & Automation';
    }

    public static function getNavigationLabel(): string
    {
        return 'Threshold Policies';
    }

    public static function form(Schema $schema): Schema
    {
        return Schemas\AutomationThresholdPolicyForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return Schemas\AutomationThresholdPolicyInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return Tables\AutomationThresholdPoliciesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAutomationThresholdPolicies::route('/'),
            'create' => Pages\CreateAutomationThresholdPolicy::route('/create'),
            'view' => Pages\ViewAutomationThresholdPolicy::route('/{record}'),
            'edit' => Pages\EditAutomationThresholdPolicy::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
