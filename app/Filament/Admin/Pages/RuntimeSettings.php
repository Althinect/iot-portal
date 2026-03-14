<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use App\Domain\Shared\Permissions\RuntimeSettingPermission;
use App\Domain\Shared\Services\RuntimeSettingManager;
use App\Domain\Shared\Services\RuntimeSettingRegistry;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\ArrayRecord;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\HtmlString;

class RuntimeSettings extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?int $navigationSort = 8;

    protected Width|string|null $maxContentWidth = 'full';

    protected string $view = 'filament.admin.pages.runtime-settings';

    public ?int $selectedOrganizationId = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->hasAnyPermission([
            RuntimeSettingPermission::VIEW->value,
            RuntimeSettingPermission::UPDATE_GLOBAL->value,
            RuntimeSettingPermission::UPDATE_ORGANIZATION->value,
        ]);
    }

    public static function getNavigationGroup(): ?string
    {
        return __('System');
    }

    public static function getNavigationLabel(): string
    {
        return __('Runtime Settings');
    }

    public function getSubheading(): ?string
    {
        return __('Manage global defaults and organization overrides for operational runtime controls from one table.');
    }

    public function mount(): void
    {
        $this->selectedOrganizationId = $this->accessibleOrganizations()->first()?->id;
    }

    public function updatedSelectedOrganizationId(mixed $organizationId): void
    {
        $this->selectOrganization($organizationId);
    }

    /**
     * @return array<int | string, string | Schema>
     */
    protected function getForms(): array
    {
        return [
            'controlsForm',
        ];
    }

    public function controlsForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->schema([
                    Grid::make([
                        'default' => 1,
                        'xl' => 4,
                    ])
                        ->schema([
                            Select::make('selectedOrganizationId')
                                ->label('Organization Context')
                                ->options($this->organizationOptions())
                                ->placeholder('No organization selected')
                                ->searchable()
                                ->live()
                                ->native(false)
                                ->disabled($this->organizationOptions() === [])
                                ->afterStateUpdated(function (mixed $state): void {
                                    $this->selectOrganization($state);
                                })
                                ->helperText($this->selectedOrganizationName()
                                    ? 'Organization overrides currently target '.$this->selectedOrganizationName().'.'
                                    : ($this->organizationOptions() === []
                                        ? 'No accessible organizations are available for tenant-specific overrides.'
                                        : 'Select an organization to expose tenant-specific override actions and effective values.'))
                                ->columnSpan([
                                    'default' => 1,
                                    'xl' => 2,
                                ]),

                            Placeholder::make('managed_controls_stat')
                                ->label('Managed Controls')
                                ->content(fn (): HtmlString => $this->statValue($this->managedSettingsCount(), 'slate'))
                                ->columnSpan(1),

                            Placeholder::make('global_overrides_stat')
                                ->label('Global Overrides')
                                ->content(fn (): HtmlString => $this->statValue($this->globalOverrideCount(), 'sky'))
                                ->columnSpan(1),

                            Placeholder::make('organization_overrides_stat')
                                ->label('Org Overrides')
                                ->content(fn (): HtmlString => $this->statValue($this->selectedOrganizationOverrideCount(), 'emerald'))
                                ->columnSpan(1),
                        ]),
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->paginated(false)
            ->records(fn (): SupportCollection => $this->tableRecords())
            ->columns([
                TextColumn::make('label')
                    ->label('Setting')
                    ->weight(FontWeight::Bold)
                    ->description(fn (array $record): string => $record['description'])
                    ->wrap(),

                TextColumn::make('global_value_label')
                    ->label('Global Override')
                    ->badge()
                    ->color(fn (array $record): string => $record['global_badge_color'])
                    ->description(fn (array $record): string => $record['global_hint'])
                    ->wrap(),

                TextColumn::make('organization_value_label')
                    ->label('Organization Override')
                    ->badge()
                    ->color(fn (array $record): string => $record['organization_badge_color'])
                    ->description(fn (array $record): string => $record['organization_hint'])
                    ->wrap(),

                TextColumn::make('effective_value_label')
                    ->label('Effective Value')
                    ->badge()
                    ->color(fn (array $record): string => $record['effective_badge_color']),

                TextColumn::make('source_label')
                    ->label('Source')
                    ->badge()
                    ->color(fn (array $record): string => $record['source_badge_color']),
            ])
            ->recordActions([
                Action::make('editGlobal')
                    ->label('Edit Global')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->color('gray')
                    ->schema(fn (array $record): array => [
                        $this->actionValueComponent($record['key'], 'value'),
                    ])
                    ->fillForm(fn (array $record): array => [
                        'value' => $record['global_form_value'],
                    ])
                    ->modalHeading(fn (array $record): string => 'Edit global override for '.$record['label'])
                    ->modalDescription('This value applies everywhere unless an organization-specific override exists.')
                    ->modalSubmitActionLabel('Save Global Override')
                    ->action(function (array $record, array $data): void {
                        abort_unless($this->canEditGlobalSettings(), 403);

                        $this->manager()->setGlobalOverrides([
                            $record['key'] => $data['value'] ?? $this->registry()->defaultValue($record['key']),
                        ]);

                        $this->resetTable();

                        Notification::make()
                            ->title('Global override saved')
                            ->success()
                            ->send();
                    })
                    ->hidden(fn (): bool => ! $this->canEditGlobalSettings()),

                Action::make('resetGlobal')
                    ->label('Reset Global')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record): string => 'Reset global override for '.$record['label'])
                    ->modalDescription('The setting will fall back to its config default unless an organization override exists.')
                    ->action(function (array $record): void {
                        abort_unless($this->canEditGlobalSettings(), 403);

                        $this->manager()->resetGlobalOverrides([$record['key']]);
                        $this->resetTable();

                        Notification::make()
                            ->title('Global override reset')
                            ->success()
                            ->send();
                    })
                    ->hidden(fn (array $record): bool => ! $this->canEditGlobalSettings() || ! $record['global_override_exists']),

                Action::make('editOrganization')
                    ->label('Edit Org')
                    ->icon(Heroicon::OutlinedBuildingOffice2)
                    ->color('primary')
                    ->schema(fn (array $record): array => [
                        $this->actionValueComponent($record['key'], 'value'),
                    ])
                    ->fillForm(fn (array $record): array => [
                        'value' => $record['organization_form_value'],
                    ])
                    ->modalHeading(fn (array $record): string => 'Edit organization override for '.$record['label'])
                    ->modalDescription(fn (): string => $this->selectedOrganization() instanceof Organization
                        ? 'This override applies only to '.$this->selectedOrganization()->name.'.'
                        : 'Select an organization first.')
                    ->modalSubmitActionLabel('Save Organization Override')
                    ->action(function (array $record, array $data): void {
                        abort_unless($this->canEditOrganizationSettings(), 403);

                        $organization = $this->selectedOrganization();

                        abort_unless($organization instanceof Organization, 403);

                        $this->manager()->setOrganizationOverrides($organization, [
                            $record['key'] => $data['value'] ?? $this->registry()->defaultValue($record['key']),
                        ]);

                        $this->resetTable();

                        Notification::make()
                            ->title('Organization override saved')
                            ->success()
                            ->send();
                    })
                    ->hidden(fn (array $record): bool => ! $record['supports_organization_overrides']
                        || ! $this->canEditOrganizationSettings()
                        || ! ($this->selectedOrganization() instanceof Organization)),

                Action::make('resetOrganization')
                    ->label('Reset Org')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record): string => 'Reset organization override for '.$record['label'])
                    ->modalDescription(fn (): string => $this->selectedOrganization() instanceof Organization
                        ? 'The selected organization will fall back to the global override or config default.'
                        : 'Select an organization first.')
                    ->action(function (array $record): void {
                        abort_unless($this->canEditOrganizationSettings(), 403);

                        $organization = $this->selectedOrganization();

                        abort_unless($organization instanceof Organization, 403);

                        $this->manager()->resetOrganizationOverrides($organization, [$record['key']]);
                        $this->resetTable();

                        Notification::make()
                            ->title('Organization override reset')
                            ->success()
                            ->send();
                    })
                    ->hidden(fn (array $record): bool => ! $record['supports_organization_overrides']
                        || ! ($this->selectedOrganization() instanceof Organization)
                        || ! $record['organization_override_exists']),
            ])
            ->defaultSort('label');
    }

    public function selectOrganization(mixed $organizationId): void
    {
        $this->selectedOrganizationId = $this->resolveAccessibleOrganization(
            $this->normalizePositiveInt($organizationId),
        )?->id;

        $this->resetTable();
    }

    public function canEditGlobalSettings(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && ($user->isSuperAdmin() || $user->hasPermissionTo(RuntimeSettingPermission::UPDATE_GLOBAL->value));
    }

    public function canEditOrganizationSettings(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && ($user->isSuperAdmin() || $user->hasPermissionTo(RuntimeSettingPermission::UPDATE_ORGANIZATION->value));
    }

    /**
     * @return array<int, string>
     */
    public function organizationOptions(): array
    {
        return $this->accessibleOrganizations()
            ->mapWithKeys(fn (Organization $organization): array => [$organization->id => (string) $organization->name])
            ->all();
    }

    public function selectedOrganizationName(): ?string
    {
        return $this->selectedOrganization()?->name;
    }

    public function managedSettingsCount(): int
    {
        return count($this->registry()->keys());
    }

    public function globalOverrideCount(): int
    {
        return count(array_filter(
            $this->manager()->resolvedSettings(),
            fn (array $resolved): bool => (bool) $resolved['global_override_exists'],
        ));
    }

    public function selectedOrganizationOverrideCount(): int
    {
        $organization = $this->selectedOrganization();

        if (! $organization instanceof Organization) {
            return 0;
        }

        return count(array_filter(
            $this->manager()->resolvedSettings($organization->id),
            fn (array $resolved): bool => (bool) $resolved['organization_override_exists'],
        ));
    }

    /**
     * @return Collection<int, Organization>
     */
    private function accessibleOrganizations(): Collection
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return new Collection;
        }

        if ($user->isSuperAdmin()) {
            return Organization::query()->orderBy('name')->get();
        }

        return $user->organizations()->orderBy('name')->get();
    }

    /**
     * @return SupportCollection<int, non-empty-array<string, mixed>>
     */
    private function tableRecords(): SupportCollection
    {
        $registry = $this->registry();
        $manager = $this->manager();
        $selectedOrganization = $this->selectedOrganization();
        $globalSettings = $manager->resolvedSettings();
        $organizationSettings = $selectedOrganization instanceof Organization
            ? $manager->resolvedSettings($selectedOrganization->id)
            : [];

        return collect($registry->keys())->map(function (string $key) use ($globalSettings, $organizationSettings, $registry, $selectedOrganization): array {
            $definition = $registry->definition($key);
            $globalResolved = $globalSettings[$key];
            $resolved = $selectedOrganization instanceof Organization
                ? $organizationSettings[$key]
                : $globalResolved;

            $globalOverrideExists = (bool) $globalResolved['global_override_exists'];
            $organizationOverrideExists = (bool) $resolved['organization_override_exists'];

            return [
                ArrayRecord::getKeyName() => $key,
                'key' => $key,
                'label' => $definition['label'],
                'description' => $definition['description'],
                'supports_organization_overrides' => $registry->supportsOrganizationOverrides($key),
                'global_override_exists' => $globalOverrideExists,
                'organization_override_exists' => $organizationOverrideExists,
                'global_form_value' => $globalOverrideExists
                    ? $globalResolved['global_override_value']
                    : $globalResolved['default_value'],
                'organization_form_value' => $organizationOverrideExists
                    ? $resolved['organization_override_value']
                    : $resolved['effective_value'],
                'global_value_label' => $globalOverrideExists
                    ? $registry->formatValue($key, $globalResolved['global_override_value'])
                    : 'Inherited',
                'global_hint' => $globalOverrideExists
                    ? 'Stored global override'
                    : 'Using config default',
                'global_badge_color' => $globalOverrideExists ? 'info' : 'gray',
                'organization_value_label' => $this->organizationValueLabel(
                    key: $key,
                    resolved: $resolved,
                    selectedOrganization: $selectedOrganization,
                ),
                'organization_hint' => $this->organizationHint(
                    key: $key,
                    organizationOverrideExists: $organizationOverrideExists,
                    selectedOrganization: $selectedOrganization,
                ),
                'organization_badge_color' => $this->organizationBadgeColor(
                    key: $key,
                    organizationOverrideExists: $organizationOverrideExists,
                    selectedOrganization: $selectedOrganization,
                ),
                'effective_value_label' => $registry->formatValue($key, $resolved['effective_value']),
                'effective_badge_color' => $this->effectiveBadgeColor(
                    key: $key,
                    effectiveValue: $resolved['effective_value'],
                ),
                'source_label' => $registry->formatSource((string) $resolved['source']),
                'source_badge_color' => $this->sourceBadgeColor((string) $resolved['source']),
            ];
        });
    }

    /**
     * @param  array{
     *     effective_value: mixed,
     *     source: string,
     *     default_value: mixed,
     *     global_override_exists: bool,
     *     global_override_value: mixed,
     *     organization_override_exists: bool,
     *     organization_override_value: mixed
     * }  $resolved
     */
    private function organizationValueLabel(string $key, array $resolved, ?Organization $selectedOrganization): string
    {
        if (! $this->registry()->supportsOrganizationOverrides($key)) {
            return 'Not Supported';
        }

        if (! $selectedOrganization instanceof Organization) {
            return 'Select Organization';
        }

        if (! (bool) $resolved['organization_override_exists']) {
            return 'Inherited';
        }

        return $this->registry()->formatValue($key, $resolved['organization_override_value']);
    }

    private function organizationHint(string $key, bool $organizationOverrideExists, ?Organization $selectedOrganization): string
    {
        if (! $this->registry()->supportsOrganizationOverrides($key)) {
            return 'This control is global only.';
        }

        if (! $selectedOrganization instanceof Organization) {
            return 'Pick an organization above to manage overrides.';
        }

        if (! $organizationOverrideExists) {
            return 'Using global override or config default for '.$selectedOrganization->name.'.';
        }

        return 'Stored override for '.$selectedOrganization->name.'.';
    }

    private function organizationBadgeColor(string $key, bool $organizationOverrideExists, ?Organization $selectedOrganization): string
    {
        if (! $this->registry()->supportsOrganizationOverrides($key)) {
            return 'gray';
        }

        if (! $selectedOrganization instanceof Organization) {
            return 'gray';
        }

        return $organizationOverrideExists ? 'primary' : 'gray';
    }

    private function effectiveBadgeColor(string $key, mixed $effectiveValue): string
    {
        return match ($this->registry()->definition($key)['type']) {
            'boolean' => (bool) $effectiveValue ? 'success' : 'danger',
            default => 'info',
        };
    }

    private function sourceBadgeColor(string $source): string
    {
        return match ($source) {
            RuntimeSettingRegistry::SOURCE_ORGANIZATION => 'primary',
            RuntimeSettingRegistry::SOURCE_GLOBAL => 'info',
            default => 'gray',
        };
    }

    private function actionValueComponent(string $settingKey, string $path): Component
    {
        $definition = $this->registry()->definition($settingKey);

        return match ($definition['type']) {
            'boolean' => Toggle::make($path)
                ->label('Enabled'),
            'select' => Select::make($path)
                ->label('Value')
                ->options($this->registry()->options($settingKey))
                ->required(),
            'integer' => TextInput::make($path)
                ->label('Value')
                ->numeric()
                ->integer()
                ->minValue($this->registry()->minimumValue($settingKey))
                ->maxValue($this->registry()->maximumValue($settingKey))
                ->required(),
        };
    }

    private function statValue(int $value, string $tone): HtmlString
    {
        $classes = match ($tone) {
            'sky' => 'text-sky-600 dark:text-sky-300',
            'emerald' => 'text-emerald-600 dark:text-emerald-300',
            default => 'text-slate-900 dark:text-white',
        };

        return new HtmlString(
            '<div class="pt-1 text-3xl font-semibold '.$classes.'">'.number_format($value).'</div>',
        );
    }

    private function resolveAccessibleOrganization(?int $organizationId): ?Organization
    {
        if ($organizationId === null) {
            return null;
        }

        return $this->accessibleOrganizations()->firstWhere('id', $organizationId);
    }

    private function selectedOrganization(): ?Organization
    {
        return $this->resolveAccessibleOrganization($this->selectedOrganizationId);
    }

    private function normalizePositiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $resolved = (int) $value;

        return $resolved > 0 ? $resolved : null;
    }

    private function manager(): RuntimeSettingManager
    {
        return app(RuntimeSettingManager::class);
    }

    private function registry(): RuntimeSettingRegistry
    {
        return app(RuntimeSettingRegistry::class);
    }
}
