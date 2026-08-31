<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\IoTDashboards;

use App\Domain\IoTDashboard\Models\IoTDashboard;
use App\Domain\Shared\Models\Entity;
use App\Domain\Shared\Models\Organization;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class IoTDashboardResource extends Resource
{
    protected static ?string $model = IoTDashboard::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Dashboard';

    protected static ?string $pluralModelLabel = 'Dashboards';

    protected static ?string $tenantOwnershipRelationshipName = 'organization';

    public static function getNavigationGroup(): ?string
    {
        return __('IoT Management');
    }

    public static function getNavigationLabel(): string
    {
        return __('Dashboards');
    }

    public static function form(Schema $schema): Schema
    {
        return Schemas\IoTDashboardForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return Schemas\IoTDashboardInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return Tables\IoTDashboardsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIoTDashboards::route('/'),
            'create' => Pages\CreateIoTDashboard::route('/create'),
            'view' => Pages\ViewIoTDashboard::route('/{record}'),
            'edit' => Pages\EditIoTDashboard::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function prepareTenantFormData(array $data): array
    {
        $organization = Filament::getTenant();
        abort_unless($organization instanceof Organization, 404);

        $entityId = $data['entity_id'] ?? null;

        if (is_numeric($entityId)) {
            $siteExists = Entity::query()
                ->where('organization_id', $organization->getKey())
                ->whereKey((int) $entityId)
                ->exists();

            if (! $siteExists) {
                throw ValidationException::withMessages([
                    'entity_id' => 'Select a site from the active organization.',
                ]);
            }
        }

        return [
            ...Arr::only($data, [
                'name',
                'slug',
                'description',
                'refresh_interval_seconds',
                'default_history_preset',
                'is_active',
            ]),
            'organization_id' => $organization->getKey(),
            'entity_id' => is_numeric($entityId) ? (int) $entityId : null,
        ];
    }
}
