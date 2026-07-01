<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Enums\ChannelDirection;
use App\Domain\DeviceProfile\Enums\ParameterCategory;
use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use App\Domain\Reporting\Actions\CreateReportRunAction;
use App\Domain\Reporting\Actions\DeleteReportRunAction;
use App\Domain\Reporting\Actions\UpdateOrganizationReportSettingsAction;
use App\Domain\Reporting\Enums\ReportGrouping;
use App\Domain\Reporting\Enums\ReportRunStatus;
use App\Domain\Reporting\Enums\ReportType;
use App\Domain\Reporting\Models\OrganizationReportSetting;
use App\Domain\Reporting\Models\ReportRun;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use App\Support\DeviceSelectOptions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Throwable;

class Reports extends Page implements HasTable
{
    use InteractsWithTable;

    private const ShiftScheduleOptionPrefix = 'shift_schedule:';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static ?int $navigationSort = 7;

    protected Width|string|null $maxContentWidth = 'full';

    protected string $view = 'filament.admin.pages.reports';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generateReport')
                ->label('Generate Report')
                ->icon(Heroicon::OutlinedPlusCircle)
                ->modalHeading('Generate Device Report')
                ->modalDescription('Queue a CSV report and keep using the UI while generation runs in the background.')
                ->modalSubmitActionLabel('Queue Report')
                ->schema($this->generateReportSchema())
                ->fillForm(fn (): array => $this->defaultGenerateReportFormData())
                ->action(function (array $data): void {
                    /** @var User|null $user */
                    $user = Auth::user();

                    if (! $user instanceof User) {
                        Notification::make()->title('You are not authenticated.')->danger()->send();

                        return;
                    }

                    $organizationId = is_numeric($data['organization_id'] ?? null) ? (int) $data['organization_id'] : 0;

                    if (! in_array($organizationId, $this->accessibleOrganizationIds(), true)) {
                        Notification::make()->title('You do not have access to this organization.')->danger()->send();

                        return;
                    }

                    $selectedType = $this->normalizeReportTypeValue($data['type'] ?? null);
                    $timezone = (string) ($data['timezone'] ?? config('app.timezone', 'UTC'));
                    $requiresAggregation = $this->requiresAggregationWindowForType($selectedType);
                    $resolvedAggregation = $this->resolveAggregationSelection(
                        value: $data['grouping'] ?? null,
                        organizationId: $organizationId,
                    );
                    $selectedGrouping = $requiresAggregation
                        ? ($resolvedAggregation['grouping'] ?? ReportGrouping::Hourly->value)
                        : null;
                    $selectedShiftSchedule = $selectedGrouping === ReportGrouping::ShiftSchedule->value
                        ? $resolvedAggregation['shift_schedule']
                        : null;
                    $fromLocal = Carbon::parse((string) ($data['from_at'] ?? now()->toDateString()), $timezone)->startOfDay();
                    $untilLocalExclusive = Carbon::parse((string) ($data['until_at'] ?? now()->toDateString()), $timezone)
                        ->addDay()
                        ->startOfDay();

                    if ($selectedGrouping === ReportGrouping::ShiftSchedule->value && $selectedShiftSchedule === null) {
                        Notification::make()
                            ->title('Invalid shift schedule')
                            ->body('Select a valid organization shift schedule.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $payload = $selectedShiftSchedule !== null
                        ? ['shift_schedule' => $selectedShiftSchedule]
                        : null;

                    $requestPayload = [
                        'organization_id' => $organizationId,
                        'device_id' => is_numeric($data['device_id'] ?? null) ? (int) $data['device_id'] : null,
                        'type' => $selectedType,
                        'grouping' => $selectedGrouping,
                        'parameter_keys' => is_array($data['parameter_keys'] ?? null) ? array_values($data['parameter_keys']) : [],
                        'from_at' => $fromLocal->toIso8601String(),
                        'until_at' => $untilLocalExclusive->toIso8601String(),
                        'timezone' => $timezone,
                        'payload' => $payload,
                        'format' => 'csv',
                    ];

                    try {
                        app(CreateReportRunAction::class)($user, $requestPayload);
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Unable to queue report')
                            ->body($this->resolveActionErrorMessage($exception))
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Report queued')
                        ->body('Generation started. The status table will update automatically.')
                        ->success()
                        ->send();
                })
                ->closeModalByClickingAway(false),

            Action::make('reportSettings')
                ->label('Settings')
                ->icon(Heroicon::OutlinedCog6Tooth)
                ->modalHeading('Report Settings')
                ->modalDescription('Configure timezone, shift schedules, and max range for each organization.')
                ->modalSubmitActionLabel('Save Settings')
                ->schema($this->settingsSchema())
                ->fillForm(fn (): array => $this->defaultSettingsFormData())
                ->action(function (array $data): void {
                    $organizationId = is_numeric($data['organization_id'] ?? null) ? (int) $data['organization_id'] : 0;

                    if (! in_array($organizationId, $this->accessibleOrganizationIds(), true)) {
                        Notification::make()->title('You do not have access to this organization.')->danger()->send();

                        return;
                    }

                    try {
                        app(UpdateOrganizationReportSettingsAction::class)([
                            'organization_id' => $organizationId,
                            'timezone' => (string) ($data['timezone'] ?? config('app.timezone', 'UTC')),
                            'max_range_days' => is_numeric($data['max_range_days'] ?? null)
                                ? (int) $data['max_range_days']
                                : $this->resolveDefaultMaxRangeDays(),
                            'shift_schedules' => is_array($data['shift_schedules'] ?? null) ? array_values($data['shift_schedules']) : [],
                        ]);
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Unable to save settings')
                            ->body($this->resolveActionErrorMessage($exception))
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()->title('Report settings saved')->success()->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        $organizationIds = $this->accessibleOrganizationIds();

        $query = ReportRun::query()
            ->with(['organization:id,name', 'device:id,name,external_id', 'requestedBy:id,name'])
            ->latest();

        if ($organizationIds === []) {
            $query->whereRaw('1 = 0');
        } else {
            $query->whereIn('organization_id', $organizationIds);
        }

        return $table
            ->query($query)
            ->columns([
                TextColumn::make('created_at')
                    ->label('Requested')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('organization.name')
                    ->label('Organization')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('device.name')
                    ->label('Device')
                    ->searchable()
                    ->description(function (ReportRun $record): ?string {
                        $externalId = $record->device?->external_id;

                        return is_string($externalId) && trim($externalId) !== ''
                            ? $externalId
                            : null;
                    }),

                TextColumn::make('type')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (ReportType|string $state): string => $state instanceof ReportType ? $state->label() : (string) $state),

                TextColumn::make('grouping')
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(fn (ReportGrouping|string|null $state): string => $state instanceof ReportGrouping ? $state->label() : ((string) $state ?: '—')),

                TextColumn::make('window')
                    ->label('Period')
                    ->state(fn (ReportRun $record): string => $record->from_at->format('Y-m-d H:i').' → '.$record->until_at->format('Y-m-d H:i')),

                TextColumn::make('timezone')
                    ->label('TZ')
                    ->badge(),

                TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    ->color(fn (ReportRunStatus|string $state): string => $state instanceof ReportRunStatus ? $state->filamentColor() : 'gray')
                    ->formatStateUsing(fn (ReportRunStatus|string $state): string => $state instanceof ReportRunStatus ? $state->label() : (string) $state),

                TextColumn::make('row_count')
                    ->label('Rows')
                    ->numeric()
                    ->placeholder('—'),

                TextColumn::make('file_size')
                    ->label('Size')
                    ->formatStateUsing(fn (mixed $state): string => $this->formatBytes($state))
                    ->placeholder('—'),

                TextColumn::make('requestedBy.name')
                    ->label('Requested By')
                    ->placeholder('System')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(ReportRunStatus::cases())
                        ->mapWithKeys(fn (ReportRunStatus $status): array => [$status->value => $status->label()])
                        ->all()),
                SelectFilter::make('organization_id')
                    ->label('Organization')
                    ->options($this->organizationOptions())
                    ->visible(fn (): bool => count($this->organizationOptions()) > 1),
            ])
            ->recordActions([
                Action::make('download')
                    ->label('Download')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('success')
                    ->visible(fn (ReportRun $record): bool => $record->isDownloadable())
                    ->url(fn (ReportRun $record): string => route('reporting.report-runs.download', ['reportRun' => $record]))
                    ->openUrlInNewTab(),

                Action::make('delete')
                    ->label('Delete')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (ReportRun $record): bool => Auth::user()?->can('delete', $record) ?? false)
                    ->action(function (ReportRun $record): void {
                        try {
                            app(DeleteReportRunAction::class)($record);
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->title('Unable to delete report')
                                ->body($this->resolveActionErrorMessage($exception))
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()->title('Report deleted')->success()->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->poll('8s')
            ->emptyStateHeading('No reports yet')
            ->emptyStateDescription('Queue a report from the "Generate Report" action.');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Analytics');
    }

    public static function getNavigationLabel(): string
    {
        return __('Reports');
    }

    public function getSubheading(): ?string
    {
        if ($this->accessibleOrganizationIds() === []) {
            return __('No organizations are assigned to your account.');
        }

        return __('Queue report generation, monitor progress, and download stored CSV files.');
    }

    /**
     * @return array{queued: int, running: int, completed: int, no_data: int, failed: int, total: int}
     */
    public function getSummaryCountsProperty(): array
    {
        $organizationIds = $this->accessibleOrganizationIds();

        if ($organizationIds === []) {
            return [
                'queued' => 0,
                'running' => 0,
                'completed' => 0,
                'no_data' => 0,
                'failed' => 0,
                'total' => 0,
            ];
        }

        /** @var array<string, int|string> $statusCounts */
        $statusCounts = ReportRun::query()
            ->whereIn('organization_id', $organizationIds)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        $queued = (int) ($statusCounts[ReportRunStatus::Queued->value] ?? 0);
        $running = (int) ($statusCounts[ReportRunStatus::Running->value] ?? 0);
        $completed = (int) ($statusCounts[ReportRunStatus::Completed->value] ?? 0);
        $noData = (int) ($statusCounts[ReportRunStatus::NoData->value] ?? 0);
        $failed = (int) ($statusCounts[ReportRunStatus::Failed->value] ?? 0);

        return [
            'queued' => $queued,
            'running' => $running,
            'completed' => $completed,
            'no_data' => $noData,
            'failed' => $failed,
            'total' => $queued + $running + $completed + $noData + $failed,
        ];
    }

    /**
     * @return array<int, Action|ActionGroup|Component>
     */
    private function generateReportSchema(): array
    {
        return [
            Select::make('organization_id')
                ->label('Organization')
                ->options($this->organizationOptions())
                ->default($this->defaultOrganizationId())
                ->required()
                ->searchable()
                ->live()
                ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                    $organizationId = is_numeric($state) ? (int) $state : null;
                    $defaults = $this->defaultGenerateReportFormData($organizationId);
                    $set('timezone', $defaults['timezone']);
                    $set(
                        'grouping',
                        $this->defaultAggregationWindowForSelection(
                            $organizationId,
                            $this->normalizeReportTypeValue($get('type')),
                        ),
                    );
                }),

            Select::make('device_id')
                ->label('Device')
                ->options(fn (Get $get): array => $this->deviceOptions($get('organization_id')))
                ->required()
                ->searchable()
                ->live()
                ->afterStateUpdated(function (Set $set, Get $get): void {
                    $reportTypeOptions = $this->reportTypeOptionsForDevice($get('device_id'));
                    $selectedType = $this->extractReportTypeValue($get('type'));
                    $resolvedType = $selectedType;

                    if ($reportTypeOptions === []) {
                        $set('type', null);
                        $set('grouping', null);
                        $set('parameter_keys', []);

                        return;
                    }

                    if (! is_string($selectedType) || ! array_key_exists($selectedType, $reportTypeOptions)) {
                        $resolvedType = (string) array_key_first($reportTypeOptions);
                        $set('type', $resolvedType);
                    }

                    $set(
                        'grouping',
                        $this->defaultAggregationWindowForSelection(
                            is_numeric($get('organization_id')) ? (int) $get('organization_id') : null,
                            $this->normalizeReportTypeValue($resolvedType),
                        ),
                    );
                    $set('parameter_keys', []);
                }),

            Grid::make(2)
                ->schema([
                    Select::make('type')
                        ->label('Report Type')
                        ->options(fn (Get $get): array => $this->reportTypeOptionsForDevice($get('device_id')))
                        ->disabled(fn (Get $get): bool => $this->reportTypeOptionsForDevice($get('device_id')) === [])
                        ->helperText('Report types are shown based on categories available for the selected device.')
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Set $set, Get $get): void {
                            $organizationId = is_numeric($get('organization_id')) ? (int) $get('organization_id') : null;
                            $reportType = $this->normalizeReportTypeValue($get('type'));
                            $set('grouping', $this->defaultAggregationWindowForSelection($organizationId, $reportType));
                            $set('parameter_keys', []);
                        }),
                    Select::make('grouping')
                        ->label('Aggregation Window')
                        ->options(fn (Get $get): array => $this->aggregationWindowOptionsForSelection(
                            organizationId: $get('organization_id'),
                            reportTypeValue: $get('type'),
                        ))
                        ->default(ReportGrouping::Hourly->value)
                        ->visible(fn (Get $get): bool => $this->requiresAggregationWindowForType($this->normalizeReportTypeValue($get('type'))))
                        ->required(fn (Get $get): bool => $this->requiresAggregationWindowForType($this->normalizeReportTypeValue($get('type'))))
                        ->helperText(fn (Get $get): ?string => $this->aggregationWindowHelperText(
                            organizationId: $get('organization_id'),
                            reportTypeValue: $get('type'),
                        )),
                ]),

            Select::make('parameter_keys')
                ->label('Parameters')
                ->multiple()
                ->searchable()
                ->options(fn (Get $get): array => $this->parameterOptionsForSelection(
                    deviceId: $get('device_id'),
                    reportTypeValue: $get('type'),
                ))
                ->helperText('Leave blank to include all parameters relevant to the selected report type.'),

            Grid::make(2)
                ->schema([
                    DatePicker::make('from_at')
                        ->label('From Date')
                        ->required(),
                    DatePicker::make('until_at')
                        ->label('To Date')
                        ->required(),
                ]),

            Select::make('timezone')
                ->label('Timezone')
                ->options($this->timezoneOptions())
                ->searchable()
                ->required(),
        ];
    }

    /**
     * @return array<int, Action|ActionGroup|Component>
     */
    private function settingsSchema(): array
    {
        return [
            Select::make('organization_id')
                ->label('Organization')
                ->options($this->organizationOptions())
                ->default($this->defaultOrganizationId())
                ->required()
                ->searchable()
                ->live()
                ->afterStateUpdated(function (Set $set, mixed $state): void {
                    $organizationId = is_numeric($state) ? (int) $state : null;
                    $defaults = $this->defaultSettingsFormData($organizationId);
                    $set('timezone', $defaults['timezone']);
                    $set('max_range_days', $defaults['max_range_days']);
                    $set('shift_schedules', $defaults['shift_schedules']);
                }),

            Select::make('timezone')
                ->label('Timezone')
                ->options($this->timezoneOptions())
                ->searchable()
                ->required(),

            TextInput::make('max_range_days')
                ->label('Max range (days)')
                ->integer()
                ->minValue(1)
                ->maxValue(366)
                ->required(),

            Repeater::make('shift_schedules')
                ->label('Shift Schedules')
                ->helperText('Each schedule should define ordered windows. Overlaps are not allowed, and gaps are allowed.')
                ->reorderable()
                ->default([])
                ->schema([
                    Hidden::make('id'),
                    TextInput::make('name')
                        ->label('Schedule Name')
                        ->required()
                        ->maxLength(100),
                    Repeater::make('windows')
                        ->label('Windows')
                        ->helperText('Use HH:MM. Cross-midnight windows are allowed (for example, 22:00 to 06:00).')
                        ->reorderable()
                        ->default([])
                        ->schema([
                            Hidden::make('id'),
                            Grid::make(3)
                                ->schema([
                                    TextInput::make('name')
                                        ->label('Window')
                                        ->required()
                                        ->maxLength(100),
                                    TextInput::make('start')
                                        ->label('Start (HH:MM)')
                                        ->required()
                                        ->regex('/^\\d{2}:\\d{2}$/'),
                                    TextInput::make('end')
                                        ->label('End (HH:MM)')
                                        ->required()
                                        ->regex('/^\\d{2}:\\d{2}$/'),
                                ]),
                        ]),
                ]),
        ];
    }

    /**
     * @return array<int|string, string>
     */
    private function organizationOptions(): array
    {
        $organizationIds = $this->accessibleOrganizationIds();

        if ($organizationIds === []) {
            return [];
        }

        return Organization::query()
            ->whereKey($organizationIds)
            ->orderBy('name', 'asc')
            ->get(['id', 'name'])
            ->mapWithKeys(fn (Organization $organization): array => [
                (int) $organization->id => (string) $organization->name,
            ])
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function accessibleOrganizationIds(): array
    {
        /** @var User|null $user */
        $user = Auth::user();

        if (! $user instanceof User) {
            return [];
        }

        if ($user->isSuperAdmin()) {
            $organizationIds = Organization::query()->orderBy('name', 'asc')->pluck('id')->all();

            /** @var array<int, int> $organizationIds */
            return $organizationIds;
        }

        $organizationIds = $user->organizations()
            ->orderBy('name', 'asc')
            ->pluck('organizations.id')
            ->all();

        /** @var array<int, int> $organizationIds */
        return $organizationIds;
    }

    private function defaultOrganizationId(): ?int
    {
        $organizationIds = $this->accessibleOrganizationIds();

        return $organizationIds[0] ?? null;
    }

    /**
     * @return array<int|string, string>|array<string, array<int|string, string>>
     */
    private function deviceOptions(mixed $organizationId): array
    {
        if (! is_numeric($organizationId)) {
            return [];
        }

        $resolvedOrganizationId = (int) $organizationId;

        if (! in_array($resolvedOrganizationId, $this->accessibleOrganizationIds(), true)) {
            return [];
        }

        return DeviceSelectOptions::groupedByType(
            Device::query()->where('organization_id', $resolvedOrganizationId),
        );
    }

    /**
     * @return array<int|string, string>
     */
    private function parameterOptionsForSelection(mixed $deviceId, mixed $reportTypeValue): array
    {
        if (! is_numeric($deviceId)) {
            return [];
        }

        $device = Device::query()->find((int) $deviceId);

        if (! $device instanceof Device) {
            return [];
        }

        $profileVersionId = (int) $device->device_profile_version_id;

        if ($profileVersionId <= 0) {
            return [];
        }

        $reportType = ReportType::tryFrom($this->normalizeReportTypeValue($reportTypeValue));
        $query = ProfileParameterDefinition::query()
            ->where('is_active', true)
            ->whereHas('channel', function (Builder $builder) use ($profileVersionId): void {
                $builder
                    ->where('device_profile_version_id', $profileVersionId)
                    ->where('direction', ChannelDirection::Publish->value);
            })
            ->orderBy('sequence');

        if ($reportType === ReportType::CounterConsumption) {
            $query->whereIn('type', [ParameterDataType::Integer->value, ParameterDataType::Decimal->value])
                ->where(function (Builder $builder): void {
                    $builder
                        ->where('category', ParameterCategory::Counter->value)
                        ->orWhere('validation_rules->category', ParameterCategory::Counter->value);
                });
        } elseif ($reportType === ReportType::StateUtilization) {
            $query->where(function (Builder $builder): void {
                $builder
                    ->where('category', ParameterCategory::State->value)
                    ->orWhereIn('validation_rules->category', ['state', 'enum']);
            });
        }

        return $query
            ->get(['key', 'label'])
            ->unique('key')
            ->mapWithKeys(fn (ProfileParameterDefinition $parameter): array => [
                $parameter->key => "{$parameter->label} ({$parameter->key})",
            ])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function reportTypeOptionsForDevice(mixed $deviceId): array
    {
        if (! is_numeric($deviceId)) {
            return [];
        }

        $device = Device::query()->find((int) $deviceId);

        if (! $device instanceof Device) {
            return [];
        }

        $profileVersionId = (int) $device->device_profile_version_id;

        if ($profileVersionId <= 0) {
            return [];
        }

        $parameterDefinitions = ProfileParameterDefinition::query()
            ->where('is_active', true)
            ->whereHas('channel', function (Builder $builder) use ($profileVersionId): void {
                $builder
                    ->where('device_profile_version_id', $profileVersionId)
                    ->where('direction', ChannelDirection::Publish->value);
            })
            ->get(['type', 'category', 'validation_rules']);

        if ($parameterDefinitions->isEmpty()) {
            return [];
        }

        $hasCounterParameters = $parameterDefinitions->contains(function (ProfileParameterDefinition $parameter): bool {
            $category = $parameter->category;
            $validationRules = is_array($parameter->validation_rules) ? $parameter->validation_rules : [];
            $validationCategory = is_string($validationRules['category'] ?? null) ? $validationRules['category'] : null;
            $dataType = $parameter->type;

            return in_array($dataType, [ParameterDataType::Integer, ParameterDataType::Decimal], true)
                && ($category === ParameterCategory::Counter || $validationCategory === ParameterCategory::Counter->value);
        });

        $hasStateParameters = $parameterDefinitions->contains(function (ProfileParameterDefinition $parameter): bool {
            $category = $parameter->category;
            $validationRules = is_array($parameter->validation_rules) ? $parameter->validation_rules : [];
            $validationCategory = is_string($validationRules['category'] ?? null) ? $validationRules['category'] : null;

            return $category === ParameterCategory::State || in_array($validationCategory, ['state', 'enum'], true);
        });

        $options = [
            ReportType::ParameterValues->value => ReportType::ParameterValues->label(),
        ];

        if ($hasCounterParameters) {
            $options[ReportType::CounterConsumption->value] = ReportType::CounterConsumption->label();
        }

        if ($hasStateParameters) {
            $options[ReportType::StateUtilization->value] = ReportType::StateUtilization->label();
        }

        return $options;
    }

    /**
     * @return array<int|string, string>
     */
    private function timezoneOptions(): array
    {
        return collect(timezone_identifiers_list())
            ->mapWithKeys(fn (string $timezone): array => [$timezone => $timezone])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function aggregationWindowOptionsForSelection(mixed $organizationId, mixed $reportTypeValue): array
    {
        $reportType = $this->normalizeReportTypeValue($reportTypeValue);

        if (! $this->requiresAggregationWindowForType($reportType)) {
            return [];
        }

        $options = collect([
            ReportGrouping::Hourly,
            ReportGrouping::Daily,
            ReportGrouping::Monthly,
        ])
            ->mapWithKeys(fn (ReportGrouping $grouping): array => [$grouping->value => $grouping->label()])
            ->all();

        if (is_numeric($organizationId)) {
            foreach ($this->resolveShiftSchedulesForOrganization((int) $organizationId) as $shiftSchedule) {
                $options[$this->shiftScheduleOptionValue($shiftSchedule['id'])] = "Shift: {$shiftSchedule['name']}";
            }
        }

        return $options;
    }

    private function aggregationWindowHelperText(mixed $organizationId, mixed $reportTypeValue): ?string
    {
        $reportType = $this->normalizeReportTypeValue($reportTypeValue);

        if (! $this->requiresAggregationWindowForType($reportType)) {
            return null;
        }

        if (! is_numeric($organizationId)) {
            return null;
        }

        if ($this->resolveShiftSchedulesForOrganization((int) $organizationId) !== []) {
            return null;
        }

        return 'Custom shift schedules are not configured for this organization.';
    }

    private function defaultAggregationWindowForSelection(?int $organizationId, string $reportTypeValue): ?string
    {
        if (! $this->requiresAggregationWindowForType($reportTypeValue)) {
            return null;
        }

        return ReportGrouping::Hourly->value;
    }

    private function requiresAggregationWindowForType(string $reportTypeValue): bool
    {
        return in_array(
            $reportTypeValue,
            [ReportType::CounterConsumption->value, ReportType::StateUtilization->value],
            true,
        );
    }

    /**
     * @return array{
     *     grouping: string|null,
     *     shift_schedule: array{
     *         id: string,
     *         name: string,
     *         windows: array<int, array{id: string, name: string, start: string, end: string}>
     *     }|null
     * }
     */
    private function resolveAggregationSelection(mixed $value, int $organizationId): array
    {
        if ($value instanceof ReportGrouping) {
            return ['grouping' => $value->value, 'shift_schedule' => null];
        }

        if (! is_string($value)) {
            return ['grouping' => null, 'shift_schedule' => null];
        }

        $resolvedGrouping = ReportGrouping::tryFrom($value);

        if ($resolvedGrouping instanceof ReportGrouping) {
            return ['grouping' => $resolvedGrouping->value, 'shift_schedule' => null];
        }

        if (! str_starts_with($value, self::ShiftScheduleOptionPrefix)) {
            return ['grouping' => null, 'shift_schedule' => null];
        }

        $scheduleId = trim(substr($value, strlen(self::ShiftScheduleOptionPrefix)));

        if ($scheduleId === '') {
            return ['grouping' => null, 'shift_schedule' => null];
        }

        foreach ($this->resolveShiftSchedulesForOrganization($organizationId) as $shiftSchedule) {
            if ($shiftSchedule['id'] !== $scheduleId) {
                continue;
            }

            return [
                'grouping' => ReportGrouping::ShiftSchedule->value,
                'shift_schedule' => $shiftSchedule,
            ];
        }

        return ['grouping' => null, 'shift_schedule' => null];
    }

    /**
     * @return array<int, array{
     *     id: string,
     *     name: string,
     *     windows: array<int, array{id: string, name: string, start: string, end: string}>
     * }>
     */
    private function resolveShiftSchedulesForOrganization(int $organizationId): array
    {
        $settings = $this->settingsForOrganization($organizationId);
        $shiftSchedules = $settings?->shift_schedules;

        if (! is_array($shiftSchedules)) {
            return [];
        }

        $resolvedShiftSchedules = [];

        foreach ($shiftSchedules as $shiftSchedule) {
            $scheduleId = trim($shiftSchedule['id']);
            $scheduleName = trim($shiftSchedule['name']);
            $windows = $shiftSchedule['windows'];

            if ($scheduleId === '' || $scheduleName === '' || $windows === []) {
                continue;
            }

            $resolvedWindows = [];

            foreach ($windows as $window) {
                $windowId = trim($window['id']);
                $windowName = trim($window['name']);
                $start = trim($window['start']);
                $end = trim($window['end']);

                if (
                    $windowId === ''
                    || $windowName === ''
                    || preg_match('/^\d{2}:\d{2}$/', $start) !== 1
                    || preg_match('/^\d{2}:\d{2}$/', $end) !== 1
                ) {
                    continue;
                }

                $resolvedWindows[] = [
                    'id' => $windowId,
                    'name' => $windowName,
                    'start' => $start,
                    'end' => $end,
                ];
            }

            if ($resolvedWindows === []) {
                continue;
            }

            $resolvedShiftSchedules[] = [
                'id' => $scheduleId,
                'name' => $scheduleName,
                'windows' => $resolvedWindows,
            ];
        }

        return $resolvedShiftSchedules;
    }

    private function shiftScheduleOptionValue(string $scheduleId): string
    {
        return self::ShiftScheduleOptionPrefix.$scheduleId;
    }

    /**
     * @return array{
     *     organization_id: int|null,
     *     type: string,
     *     grouping: string,
     *     from_at: Carbon,
     *     until_at: Carbon,
     *     timezone: string
     * }
     */
    private function defaultGenerateReportFormData(?int $organizationId = null): array
    {
        $resolvedOrganizationId = $organizationId ?? $this->defaultOrganizationId();
        $settings = $resolvedOrganizationId !== null ? $this->settingsForOrganization($resolvedOrganizationId) : null;

        return [
            'organization_id' => $resolvedOrganizationId,
            'type' => ReportType::ParameterValues->value,
            'grouping' => $this->normalizeReportGroupingValue(ReportGrouping::Hourly),
            'from_at' => now()->subDay()->startOfDay(),
            'until_at' => now()->subDay()->startOfDay(),
            'timezone' => $settings instanceof OrganizationReportSetting
                ? $settings->timezone
                : $this->resolveDefaultTimezone(),
        ];
    }

    /**
     * @return array{
     *     organization_id: int|null,
     *     timezone: string,
     *     max_range_days: int,
     *     shift_schedules: array<int, array{
     *         id: string,
     *         name: string,
     *         windows: array<int, array{id: string, name: string, start: string, end: string}>
     *     }>
     * }
     */
    private function defaultSettingsFormData(?int $organizationId = null): array
    {
        $resolvedOrganizationId = $organizationId ?? $this->defaultOrganizationId();
        $settings = $resolvedOrganizationId !== null ? $this->settingsForOrganization($resolvedOrganizationId) : null;

        return [
            'organization_id' => $resolvedOrganizationId,
            'timezone' => $settings instanceof OrganizationReportSetting
                ? $settings->timezone
                : $this->resolveDefaultTimezone(),
            'max_range_days' => $settings instanceof OrganizationReportSetting
                ? $settings->max_range_days
                : $this->resolveDefaultMaxRangeDays(),
            'shift_schedules' => is_array($settings?->shift_schedules) ? $settings->shift_schedules : [],
        ];
    }

    private function settingsForOrganization(int $organizationId): ?OrganizationReportSetting
    {
        return OrganizationReportSetting::query()
            ->where('organization_id', $organizationId)
            ->first();
    }

    private function normalizeReportTypeValue(mixed $value): string
    {
        if ($value instanceof ReportType) {
            return $value->value;
        }

        if (is_string($value)) {
            $resolvedType = ReportType::tryFrom($value);

            return $resolvedType instanceof ReportType
                ? $resolvedType->value
                : ReportType::ParameterValues->value;
        }

        return ReportType::ParameterValues->value;
    }

    private function extractReportTypeValue(mixed $value): ?string
    {
        if ($value instanceof ReportType) {
            return $value->value;
        }

        if (is_string($value)) {
            $resolvedType = ReportType::tryFrom($value);

            return $resolvedType instanceof ReportType ? $resolvedType->value : null;
        }

        return null;
    }

    private function normalizeReportGroupingValue(mixed $value): string
    {
        if ($value instanceof ReportGrouping) {
            return $value->value;
        }

        if (! is_string($value)) {
            return ReportGrouping::Hourly->value;
        }

        $resolvedGrouping = ReportGrouping::tryFrom($value);

        return $resolvedGrouping instanceof ReportGrouping
            ? $resolvedGrouping->value
            : ReportGrouping::Hourly->value;
    }

    private function formatBytes(mixed $value): string
    {
        if (! is_numeric($value) || (int) $value <= 0) {
            return '—';
        }

        $bytes = (float) $value;
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = (int) floor(log($bytes, 1024));
        $power = max(0, min($power, count($units) - 1));
        $normalized = $bytes / (1024 ** $power);

        return number_format($normalized, $power === 0 ? 0 : 1).' '.$units[$power];
    }

    private function resolveDefaultTimezone(): string
    {
        $timezone = config('app.timezone', 'UTC');

        return is_string($timezone) ? $timezone : 'UTC';
    }

    private function resolveDefaultMaxRangeDays(): int
    {
        $value = config('reporting.default_max_range_days', 31);

        return is_numeric($value) ? (int) $value : 31;
    }

    private function resolveActionErrorMessage(Throwable $exception): string
    {
        if ($exception instanceof ValidationException) {
            $message = collect($exception->errors())
                ->flatten()
                ->first(fn (mixed $error): bool => is_string($error) && trim($error) !== '');

            return is_string($message) ? $message : 'Please review the form input and try again.';
        }

        $message = trim($exception->getMessage());

        return $message !== '' ? $message : 'Please try again.';
    }
}
