<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\IoTDashboardSupport;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\IoTDashboard\Enums\WidgetType;
use App\Domain\IoTDashboard\Models\IoTDashboard;
use App\Domain\IoTDashboard\Widgets\BarChart\BarInterval;
use App\Domain\IoTDashboard\Widgets\CompressorUtilization\CompressorUtilizationConfig;
use App\Domain\IoTDashboard\Widgets\GaugeChart\GaugeStyle;
use App\Domain\IoTDashboard\Widgets\StateCard\StateCardStyle;
use App\Domain\IoTDashboard\Widgets\SteamMeter\SteamMeterConfig;
use App\Domain\IoTDashboard\Widgets\StenterUtilization\StenterUtilizationConfig;
use Closure;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class WidgetFormSchemaFactory
{
    public function __construct(
        private readonly WidgetFormOptionsService $optionsService,
        private readonly WidgetLayoutService $layoutService,
    ) {}

    /**
     * @return array<int, Component>
     */
    public function lineSchema(IoTDashboard $dashboard): array
    {
        return [
            TextInput::make('title')
                ->label('Widget title')
                ->required()
                ->default('Line Chart')
                ->maxLength(255),
            ...$this->baseScopeSchema($dashboard, defaultTitle: 'Line Chart'),
            Select::make('parameter_keys')
                ->label('Series parameters')
                ->multiple()
                ->options(fn (Get $get): array => $this->optionsService->parameterOptions($get('device_channel_id')))
                ->helperText('Choose one or more parameters. Colors are assigned automatically.')
                ->required(),
            ...$this->transportSchema(true, true, 10, 120, 240),
            ...$this->layoutSchema(),
        ];
    }

    /**
     * @return array<int, Component>
     */
    public function barSchema(IoTDashboard $dashboard): array
    {
        return [
            TextInput::make('title')
                ->label('Widget title')
                ->required()
                ->default('Energy Consumption')
                ->maxLength(255),
            ...$this->baseScopeSchema($dashboard, defaultTitle: 'Energy Consumption'),
            Select::make('parameter_key')
                ->label('Counter parameter')
                ->options(fn (Get $get): array => $this->optionsService->counterParameterOptions($get('device_channel_id')))
                ->required(),
            Select::make('bar_interval')
                ->label('Aggregation interval')
                ->options(BarInterval::class)
                ->default(BarInterval::Hourly->value)
                ->required(),
            ...$this->transportSchema(false, true, 60, 43200, 31),
            ...$this->layoutSchema(),
        ];
    }

    /**
     * @return array<int, Component>
     */
    public function gaugeSchema(IoTDashboard $dashboard): array
    {
        return [
            TextInput::make('title')
                ->label('Widget title')
                ->required()
                ->default('Gauge')
                ->maxLength(255),
            ...$this->baseScopeSchema($dashboard, defaultTitle: 'Gauge'),
            Select::make('parameter_key')
                ->label('Gauge parameter')
                ->options(fn (Get $get): array => $this->optionsService->numericParameterOptions($get('device_channel_id')))
                ->required(),
            Select::make('gauge_style')
                ->label('Gauge style')
                ->options(GaugeStyle::class)
                ->default(GaugeStyle::Classic->value)
                ->required(),
            Grid::make(2)
                ->schema([
                    TextInput::make('gauge_min')->label('Minimum')->numeric()->default(0)->required(),
                    TextInput::make('gauge_max')->label('Maximum')->numeric()->default(100)->required(),
                ]),
            Repeater::make('gauge_ranges')
                ->label('Color ranges')
                ->default([
                    ['from' => 0, 'to' => 50, 'color' => '#10b981'],
                    ['from' => 50, 'to' => 80, 'color' => '#f59e0b'],
                    ['from' => 80, 'to' => 100, 'color' => '#ef4444'],
                ])
                ->minItems(1)
                ->maxItems(10)
                ->reorderable()
                ->schema([
                    TextInput::make('from')->numeric()->required(),
                    TextInput::make('to')->numeric()->required(),
                    ColorPicker::make('color')->required(),
                ])
                ->columns(3)
                ->columnSpanFull(),
            ...$this->transportSchema(true, true, 10, 180, 1),
            ...$this->layoutSchema(),
        ];
    }

    /**
     * @return array<int, Component>
     */
    public function statusSummarySchema(IoTDashboard $dashboard): array
    {
        return [
            TextInput::make('title')
                ->label('Widget title')
                ->required()
                ->default('Latest Status')
                ->maxLength(255),
            ...$this->statusSummaryScopeSchema($dashboard),
            ...$this->statusSummaryRowsSchema($dashboard),
            ...$this->transportSchema(true, true, 10, 180, 1),
            ...$this->layoutSchema(),
        ];
    }

    /**
     * @return array<int, Component>
     */
    public function stateCardSchema(IoTDashboard $dashboard): array
    {
        return [
            TextInput::make('title')
                ->label('Widget title')
                ->required()
                ->default('State Card')
                ->maxLength(255),
            ...$this->baseScopeSchema($dashboard, defaultTitle: 'State Card'),
            Select::make('parameter_key')
                ->label('State parameter')
                ->options(fn (Get $get): array => $this->optionsService->stateParameterOptions($get('device_channel_id')))
                ->required(),
            Select::make('display_style')
                ->label('Display style')
                ->options(StateCardStyle::class)
                ->default(StateCardStyle::Toggle->value)
                ->required(),
            ...$this->stateMappingsSchema(),
            ...$this->transportSchema(true, true, 10, 1440, 1),
            ...$this->layoutSchema(),
        ];
    }

    /**
     * @return array<int, Component>
     */
    public function stateTimelineSchema(IoTDashboard $dashboard): array
    {
        return [
            TextInput::make('title')
                ->label('Widget title')
                ->required()
                ->default('State Timeline')
                ->maxLength(255),
            ...$this->baseScopeSchema($dashboard, defaultTitle: 'State Timeline'),
            Select::make('parameter_key')
                ->label('State parameter')
                ->options(fn (Get $get): array => $this->optionsService->stateParameterOptions($get('device_channel_id')))
                ->required(),
            ...$this->stateMappingsSchema(),
            ...$this->transportSchema(true, true, 10, 360, 240),
            ...$this->layoutSchema(),
        ];
    }

    /**
     * @return array<int, Component>
     */
    public function thresholdStatusGridSchema(IoTDashboard $dashboard): array
    {
        return [
            TextInput::make('title')
                ->label('Widget title')
                ->required()
                ->default('Cold Room Threshold Status')
                ->maxLength(255),
            Select::make('display_mode')
                ->label('Display mode')
                ->options($this->thresholdStatusGridDisplayModeOptions())
                ->default('standard')
                ->required(),
            Select::make('scope')
                ->label('Policy scope')
                ->options($this->thresholdStatusGridScopeOptions())
                ->default('all_active')
                ->required()
                ->live(),
            Select::make('policy_ids')
                ->label('Threshold policies')
                ->multiple()
                ->searchable()
                ->options(fn (): array => $this->optionsService->thresholdPolicyOptions($dashboard))
                ->visible(fn (Get $get): bool => $get('scope') === 'selected')
                ->required(fn (Get $get): bool => $get('scope') === 'selected'),
            $this->thresholdStatusGridDeviceCardsRepeater($dashboard)
                ->visible(fn (Get $get): bool => $get('scope') === 'device_cards')
                ->required(fn (Get $get): bool => $get('scope') === 'device_cards'),
            ...$this->transportSchema(false, true, 15, 180, 1),
            ...$this->layoutSchema('24', 840),
        ];
    }

    /**
     * @return array<int, Component>
     */
    public function thresholdStatusCardSchema(IoTDashboard $dashboard): array
    {
        return [
            TextInput::make('title')
                ->label('Widget title')
                ->required()
                ->default('Threshold Status')
                ->maxLength(255),
            Select::make('policy_id')
                ->label('Threshold policy')
                ->searchable()
                ->options(fn (): array => $this->optionsService->thresholdPolicyOptions($dashboard))
                ->required(),
            ...$this->layoutSchema('4', 320),
        ];
    }

    /**
     * @return array<int, Component>
     */
    public function stenterUtilizationSchema(IoTDashboard $dashboard): array
    {
        return [
            TextInput::make('title')
                ->label('Widget title')
                ->required()
                ->default('Stenter Utilization')
                ->maxLength(255),
            $this->deviceSelect(
                dashboard: $dashboard,
                label: 'Stenter',
                optionsResolver: fn (): array => $this->optionsService->stenterDeviceOptions($dashboard),
                defaultTitle: 'Stenter Utilization',
            ),
            $this->stenterShiftsRepeater(),
            $this->stenterPercentageThresholdsRepeater(),
            ...$this->transportSchema(false, true, 30, 1440, 60),
            ...$this->layoutSchema('4', 768),
        ];
    }

    /**
     * @return array<int, Component>
     */
    public function compressorUtilizationSchema(IoTDashboard $dashboard): array
    {
        return [
            TextInput::make('title')
                ->label('Widget title')
                ->required()
                ->default('Compressor Utilization')
                ->maxLength(255),
            $this->deviceSelect(
                dashboard: $dashboard,
                label: 'Compressor',
                optionsResolver: fn (): array => $this->optionsService->compressorDeviceOptions($dashboard),
                defaultTitle: 'Compressor Utilization',
            ),
            $this->compressorShiftsRepeater(),
            $this->compressorPercentageThresholdsRepeater(),
            ...$this->transportSchema(false, true, 30, 1440, 60),
            ...$this->layoutSchema('4', 600),
        ];
    }

    /**
     * @return array<int, Component>
     */
    public function steamMeterSchema(IoTDashboard $dashboard): array
    {
        return [
            TextInput::make('title')
                ->label('Widget title')
                ->required()
                ->default('Steam Meter')
                ->maxLength(255),
            $this->deviceSelect(
                dashboard: $dashboard,
                label: 'Steam meter',
                optionsResolver: fn (): array => $this->optionsService->steamMeterDeviceOptions($dashboard),
                defaultTitle: 'Steam Meter',
            ),
            $this->steamMeterShiftsRepeater(),
            ...$this->transportSchema(false, true, 30, 1440, 1),
            ...$this->layoutSchema('4', 520),
        ];
    }

    /**
     * @return array<int, Component>
     */
    public function editSchema(IoTDashboard $dashboard): array
    {
        return [
            Hidden::make('widget_type')->default(WidgetType::LineChart->value),
            TextInput::make('title')->label('Widget title')->required()->maxLength(255),
            ...$this->baseScopeSchema(
                dashboard: $dashboard,
                visibleCondition: fn (Get $get): bool => ! in_array($get('widget_type'), [
                    WidgetType::ThresholdStatusCard->value,
                    WidgetType::ThresholdStatusGrid->value,
                    WidgetType::StenterUtilization->value,
                    WidgetType::CompressorUtilization->value,
                    WidgetType::SteamMeter->value,
                ], true),
            ),
            $this->deviceSelect(
                dashboard: $dashboard,
                label: 'Stenter',
                optionsResolver: fn (): array => $this->optionsService->stenterDeviceOptions($dashboard),
                condition: fn (Get $get): bool => $get('widget_type') === WidgetType::StenterUtilization->value,
            ),
            $this->deviceSelect(
                dashboard: $dashboard,
                label: 'Compressor',
                optionsResolver: fn (): array => $this->optionsService->compressorDeviceOptions($dashboard),
                condition: fn (Get $get): bool => $get('widget_type') === WidgetType::CompressorUtilization->value,
            ),
            $this->deviceSelect(
                dashboard: $dashboard,
                label: 'Steam meter',
                optionsResolver: fn (): array => $this->optionsService->steamMeterDeviceOptions($dashboard),
                condition: fn (Get $get): bool => $get('widget_type') === WidgetType::SteamMeter->value,
            ),
            Select::make('policy_id')
                ->label('Threshold policy')
                ->searchable()
                ->options(fn (): array => $this->optionsService->thresholdPolicyOptions($dashboard))
                ->visible(fn (Get $get): bool => $get('widget_type') === WidgetType::ThresholdStatusCard->value)
                ->required(fn (Get $get): bool => $get('widget_type') === WidgetType::ThresholdStatusCard->value),
            Select::make('parameter_keys')
                ->label('Series parameters')
                ->multiple()
                ->options(fn (Get $get): array => $this->optionsService->parameterOptions($get('device_channel_id')))
                ->visible(fn (Get $get): bool => $get('widget_type') === WidgetType::LineChart->value)
                ->required(fn (Get $get): bool => $get('widget_type') === WidgetType::LineChart->value),
            Select::make('parameter_key')
                ->label('Parameter')
                ->options(function (Get $get): array {
                    $topicId = $get('device_channel_id');
                    $type = $get('widget_type');

                    if ($type === WidgetType::BarChart->value) {
                        return $this->optionsService->counterParameterOptions($topicId);
                    }

                    if (in_array($type, [WidgetType::StateCard->value, WidgetType::StateTimeline->value], true)) {
                        return $this->optionsService->stateParameterOptions($topicId);
                    }

                    return $this->optionsService->numericParameterOptions($topicId);
                })
                ->visible(fn (Get $get): bool => in_array($get('widget_type'), [
                    WidgetType::BarChart->value,
                    WidgetType::GaugeChart->value,
                    WidgetType::StateCard->value,
                    WidgetType::StateTimeline->value,
                ], true))
                ->required(fn (Get $get): bool => in_array($get('widget_type'), [
                    WidgetType::BarChart->value,
                    WidgetType::GaugeChart->value,
                    WidgetType::StateCard->value,
                    WidgetType::StateTimeline->value,
                ], true)),
            Select::make('bar_interval')
                ->label('Aggregation interval')
                ->options(BarInterval::class)
                ->visible(fn (Get $get): bool => $get('widget_type') === WidgetType::BarChart->value)
                ->required(fn (Get $get): bool => $get('widget_type') === WidgetType::BarChart->value),
            Select::make('gauge_style')
                ->label('Gauge style')
                ->options(GaugeStyle::class)
                ->visible(fn (Get $get): bool => $get('widget_type') === WidgetType::GaugeChart->value)
                ->required(fn (Get $get): bool => $get('widget_type') === WidgetType::GaugeChart->value),
            Grid::make(2)
                ->schema([
                    TextInput::make('gauge_min')->numeric()->required(),
                    TextInput::make('gauge_max')->numeric()->required(),
                ])
                ->visible(fn (Get $get): bool => $get('widget_type') === WidgetType::GaugeChart->value),
            Repeater::make('gauge_ranges')
                ->label('Color ranges')
                ->minItems(1)
                ->maxItems(10)
                ->reorderable()
                ->schema([
                    TextInput::make('from')->numeric()->required(),
                    TextInput::make('to')->numeric()->required(),
                    ColorPicker::make('color')->required(),
                ])
                ->columns(3)
                ->columnSpanFull()
                ->visible(fn (Get $get): bool => $get('widget_type') === WidgetType::GaugeChart->value),
            Select::make('display_style')
                ->label('Display style')
                ->options(StateCardStyle::class)
                ->visible(fn (Get $get): bool => $get('widget_type') === WidgetType::StateCard->value)
                ->required(fn (Get $get): bool => $get('widget_type') === WidgetType::StateCard->value),
            Repeater::make('state_mappings')
                ->label('State mappings')
                ->default($this->defaultStateMappings())
                ->minItems(1)
                ->maxItems(12)
                ->reorderable()
                ->schema([
                    TextInput::make('value')->required(),
                    TextInput::make('label')->required(),
                    ColorPicker::make('color')->required(),
                ])
                ->columns(3)
                ->helperText('Map stored state values to labels and colors. You can override schema defaults per widget.')
                ->columnSpanFull()
                ->visible(fn (Get $get): bool => in_array($get('widget_type'), [WidgetType::StateCard->value, WidgetType::StateTimeline->value], true)),
            $this->statusSummaryRowsRepeater($dashboard)
                ->visible(fn (Get $get): bool => $get('widget_type') === WidgetType::StatusSummary->value)
                ->required(fn (Get $get): bool => $get('widget_type') === WidgetType::StatusSummary->value),
            Select::make('display_mode')
                ->label('Display mode')
                ->options($this->thresholdStatusGridDisplayModeOptions())
                ->default('standard')
                ->visible(fn (Get $get): bool => $get('widget_type') === WidgetType::ThresholdStatusGrid->value)
                ->required(fn (Get $get): bool => $get('widget_type') === WidgetType::ThresholdStatusGrid->value),
            Select::make('scope')
                ->label('Policy scope')
                ->options($this->thresholdStatusGridScopeOptions())
                ->default('all_active')
                ->visible(fn (Get $get): bool => $get('widget_type') === WidgetType::ThresholdStatusGrid->value)
                ->required(fn (Get $get): bool => $get('widget_type') === WidgetType::ThresholdStatusGrid->value)
                ->live(),
            Select::make('policy_ids')
                ->label('Threshold policies')
                ->multiple()
                ->searchable()
                ->options(fn (): array => $this->optionsService->thresholdPolicyOptions($dashboard))
                ->visible(fn (Get $get): bool => $get('widget_type') === WidgetType::ThresholdStatusGrid->value && $get('scope') === 'selected')
                ->required(fn (Get $get): bool => $get('widget_type') === WidgetType::ThresholdStatusGrid->value && $get('scope') === 'selected'),
            $this->thresholdStatusGridDeviceCardsRepeater($dashboard)
                ->visible(fn (Get $get): bool => $get('widget_type') === WidgetType::ThresholdStatusGrid->value && $get('scope') === 'device_cards')
                ->required(fn (Get $get): bool => $get('widget_type') === WidgetType::ThresholdStatusGrid->value && $get('scope') === 'device_cards'),
            $this->stenterShiftsRepeater()
                ->visible(fn (Get $get): bool => $get('widget_type') === WidgetType::StenterUtilization->value)
                ->required(fn (Get $get): bool => $get('widget_type') === WidgetType::StenterUtilization->value),
            $this->stenterPercentageThresholdsRepeater()
                ->visible(fn (Get $get): bool => $get('widget_type') === WidgetType::StenterUtilization->value)
                ->required(fn (Get $get): bool => $get('widget_type') === WidgetType::StenterUtilization->value),
            $this->compressorShiftsRepeater()
                ->visible(fn (Get $get): bool => $get('widget_type') === WidgetType::CompressorUtilization->value)
                ->required(fn (Get $get): bool => $get('widget_type') === WidgetType::CompressorUtilization->value),
            $this->compressorPercentageThresholdsRepeater()
                ->visible(fn (Get $get): bool => $get('widget_type') === WidgetType::CompressorUtilization->value)
                ->required(fn (Get $get): bool => $get('widget_type') === WidgetType::CompressorUtilization->value),
            $this->steamMeterShiftsRepeater()
                ->visible(fn (Get $get): bool => $get('widget_type') === WidgetType::SteamMeter->value)
                ->required(fn (Get $get): bool => $get('widget_type') === WidgetType::SteamMeter->value),
            ...$this->transportSchema(
                true,
                true,
                10,
                120,
                240,
                fn (Get $get): bool => $get('widget_type') !== WidgetType::ThresholdStatusCard->value,
            ),
            ...$this->layoutSchema(),
        ];
    }

    /**
     * @return array<int, Component>
     */
    private function baseScopeSchema(
        IoTDashboard $dashboard,
        ?Closure $visibleCondition = null,
        ?string $defaultTitle = null,
    ): array {
        $visibleCondition ??= static fn (): bool => true;

        return [
            $this->deviceSelect(
                dashboard: $dashboard,
                label: 'Device',
                optionsResolver: fn (): array => $this->optionsService->deviceOptions($dashboard),
                condition: $visibleCondition,
                defaultTitle: $defaultTitle,
            ),
            Select::make('device_channel_id')
                ->label('Publish topic')
                ->options(fn (Get $get): array => $this->optionsService->topicOptions($dashboard, $get('device_id')))
                ->searchable()
                ->required($visibleCondition)
                ->visible($visibleCondition)
                ->live(),
        ];
    }

    /**
     * @return array<int, Component>
     */
    private function statusSummaryScopeSchema(IoTDashboard $dashboard): array
    {
        return [
            $this->deviceSelect(
                dashboard: $dashboard,
                label: 'Device',
                optionsResolver: fn (): array => $this->optionsService->deviceOptions($dashboard),
                defaultTitle: 'Latest Status',
            ),
            Select::make('device_channel_id')
                ->label('Publish topic')
                ->options(fn (Get $get): array => $this->optionsService->topicOptions($dashboard, $get('device_id')))
                ->searchable()
                ->required()
                ->live()
                ->afterStateUpdated(function (Set $set, mixed $state): void {
                    $set('rows', $this->optionsService->statusSummaryDefaultRows($state));
                }),
        ];
    }

    /**
     * @return array<int, Component>
     */
    private function transportSchema(
        bool $defaultWebsocket,
        bool $defaultPolling,
        int $defaultPollingSeconds,
        int $defaultLookback,
        int $defaultMaxPoints,
        ?Closure $visibleCondition = null,
    ): array {
        $visibleCondition ??= static fn (): bool => true;

        return [
            Grid::make(2)
                ->schema([
                    Toggle::make('use_websocket')->default($defaultWebsocket),
                    Toggle::make('use_polling')->default($defaultPolling)->live(),
                ])
                ->visible($visibleCondition),
            Grid::make(3)
                ->schema([
                    TextInput::make('polling_interval_seconds')
                        ->integer()
                        ->minValue(2)
                        ->maxValue(300)
                        ->default($defaultPollingSeconds)
                        ->visible(fn (Get $get): bool => (bool) $get('use_polling')),
                    TextInput::make('lookback_minutes')
                        ->integer()
                        ->minValue(1)
                        ->maxValue(129600)
                        ->default($defaultLookback)
                        ->required(),
                    TextInput::make('max_points')
                        ->integer()
                        ->minValue(1)
                        ->maxValue(1000)
                        ->default($defaultMaxPoints)
                        ->required(),
                ])
                ->visible($visibleCondition),
        ];
    }

    /**
     * @return array<int, Component>
     */
    private function layoutSchema(string $defaultGridColumns = '6', int $defaultCardHeightPx = 360): array
    {
        return [
            Grid::make(2)
                ->schema([
                    Select::make('grid_columns')
                        ->label('Widget width')
                        ->options(fn (): array => $this->layoutService->gridColumnOptions())
                        ->default($defaultGridColumns)
                        ->required(),
                    TextInput::make('card_height_px')
                        ->label('Initial height (px)')
                        ->integer()
                        ->minValue(260)
                        ->maxValue(900)
                        ->default($defaultCardHeightPx)
                        ->required(),
                ]),
        ];
    }

    private function deviceSelect(
        IoTDashboard $dashboard,
        string $label,
        Closure $optionsResolver,
        ?Closure $condition = null,
        ?string $defaultTitle = null,
    ): Select {
        $select = Select::make('device_id')
            ->label($label)
            ->options($optionsResolver)
            ->searchable()
            ->live()
            ->afterStateUpdated(function (Set $set, Get $get, mixed $state, mixed $old) use ($dashboard, $defaultTitle): void {
                $this->autoPopulateTitleFromDeviceSelection(
                    dashboard: $dashboard,
                    set: $set,
                    get: $get,
                    deviceId: $state,
                    previousDeviceId: $old,
                    defaultTitle: $defaultTitle,
                );
            });

        if ($condition !== null) {
            return $select
                ->visible($condition)
                ->required($condition);
        }

        return $select->required();
    }

    private function autoPopulateTitleFromDeviceSelection(
        IoTDashboard $dashboard,
        Set $set,
        Get $get,
        mixed $deviceId,
        mixed $previousDeviceId,
        ?string $defaultTitle = null,
    ): void {
        $resolvedTitle = $this->resolveAutofilledTitleForDeviceSelection(
            dashboard: $dashboard,
            deviceId: $deviceId,
            previousDeviceId: $previousDeviceId,
            currentTitle: $get('title'),
            defaultTitle: $defaultTitle,
        );

        if ($resolvedTitle === null) {
            return;
        }

        $set('title', $resolvedTitle);
    }

    public function resolveAutofilledTitleForDeviceSelection(
        IoTDashboard $dashboard,
        mixed $deviceId,
        mixed $previousDeviceId,
        mixed $currentTitle,
        ?string $defaultTitle = null,
    ): ?string {
        if (! is_numeric($deviceId)) {
            return null;
        }

        $selectedDevice = Device::query()
            ->whereKey((int) $deviceId)
            ->where('organization_id', $dashboard->organization_id)
            ->first(['id', 'name']);

        if (! $selectedDevice instanceof Device) {
            return null;
        }

        $currentTitle = is_string($currentTitle) ? trim($currentTitle) : '';

        if ($currentTitle !== '' && ! $this->shouldReplaceWidgetTitle($dashboard, $currentTitle, $previousDeviceId, $defaultTitle)) {
            return null;
        }

        return $selectedDevice->name;
    }

    private function shouldReplaceWidgetTitle(
        IoTDashboard $dashboard,
        string $currentTitle,
        mixed $previousDeviceId,
        ?string $defaultTitle = null,
    ): bool {
        if ($defaultTitle !== null && $currentTitle === $defaultTitle) {
            return true;
        }

        if (! is_numeric($previousDeviceId)) {
            return false;
        }

        $previousDevice = Device::query()
            ->whereKey((int) $previousDeviceId)
            ->where('organization_id', $dashboard->organization_id)
            ->first(['id', 'name']);

        if (! $previousDevice instanceof Device) {
            return false;
        }

        return $currentTitle === $previousDevice->name;
    }

    /**
     * @return array<int, Component>
     */
    private function statusSummaryRowsSchema(IoTDashboard $dashboard): array
    {
        return [
            $this->statusSummaryRowsRepeater($dashboard)->required(),
        ];
    }

    /**
     * @return array<int, Component>
     */
    private function statusSummaryRowFields(IoTDashboard $dashboard): array
    {
        return [
            Repeater::make('tiles')
                ->label('Tiles')
                ->minItems(1)
                ->maxItems(8)
                ->reorderable()
                ->grid(4)
                ->itemLabel(fn (array $state): string => $this->statusSummaryTilePreviewLabel($state))
                ->schema($this->statusSummaryTileFields($dashboard))
                ->helperText('Configure each tile in this row. Tiles in the same row share the width equally.')
                ->columnSpanFull()
                ->required(),
        ];
    }

    /**
     * @return array<int, Component>
     */
    private function stateMappingsSchema(): array
    {
        return [
            Repeater::make('state_mappings')
                ->label('State mappings')
                ->default($this->defaultStateMappings())
                ->minItems(1)
                ->maxItems(12)
                ->reorderable()
                ->schema([
                    TextInput::make('value')->required(),
                    TextInput::make('label')->required(),
                    ColorPicker::make('color')->required(),
                ])
                ->columns(3)
                ->helperText('Map stored state values to labels and colors. You can override schema defaults per widget.')
                ->columnSpanFull(),
        ];
    }

    /**
     * @return array<int, array{value: string, label: string, color: string}>
     */
    private function defaultStateMappings(): array
    {
        return [
            ['value' => '0', 'label' => 'OFF', 'color' => '#ef4444'],
            ['value' => '1', 'label' => 'ON', 'color' => '#22c55e'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function thresholdStatusGridDisplayModeOptions(): array
    {
        return [
            'standard' => 'Standard grid',
            'sri_lankan_temperature' => 'SriLankan temperature cards',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function thresholdStatusGridScopeOptions(): array
    {
        return [
            'all_active' => 'All active threshold policies',
            'selected' => 'Selected threshold policies',
            'device_cards' => 'Configured devices',
        ];
    }

    private function thresholdStatusGridDeviceCardsRepeater(IoTDashboard $dashboard): Repeater
    {
        return Repeater::make('device_cards')
            ->label('Device cards')
            ->default([])
            ->minItems(1)
            ->reorderable()
            ->schema([
                Select::make('device_id')
                    ->label('Device')
                    ->options(fn (): array => $this->optionsService->deviceOptions($dashboard))
                    ->searchable()
                    ->required(),
                TextInput::make('label')
                    ->label('Card label')
                    ->maxLength(80),
                TextInput::make('parameter_key')
                    ->label('Parameter key')
                    ->default('temperature')
                    ->required()
                    ->maxLength(100),
                TextInput::make('minimum_value')
                    ->label('Min')
                    ->numeric(),
                TextInput::make('maximum_value')
                    ->label('Max')
                    ->numeric(),
            ])
            ->columns(5)
            ->columnSpanFull()
            ->helperText('Configure a fixed device set when this widget should mirror SriLankan production temperature cards.');
    }

    private function stenterShiftsRepeater(): Repeater
    {
        return Repeater::make('shifts')
            ->label('Shifts (UTC)')
            ->default(StenterUtilizationConfig::defaultShifts())
            ->minItems(1)
            ->maxItems(6)
            ->reorderable()
            ->schema([
                TextInput::make('label')
                    ->required()
                    ->maxLength(40),
                TextInput::make('start_time')
                    ->label('Start UTC')
                    ->placeholder('06:00')
                    ->regex('/^([01]\\d|2[0-3]):[0-5]\\d$/')
                    ->required(),
                TextInput::make('end_time')
                    ->label('End UTC')
                    ->placeholder('14:00')
                    ->regex('/^([01]\\d|2[0-3]):[0-5]\\d$/')
                    ->required(),
            ])
            ->columns(3)
            ->columnSpanFull()
            ->helperText('Store shift windows in UTC. The widget displays these times in each viewer’s local timezone.');
    }

    private function stenterPercentageThresholdsRepeater(): Repeater
    {
        return Repeater::make('percentage_thresholds')
            ->label('Percentage thresholds')
            ->default(StenterUtilizationConfig::defaultPercentageThresholds())
            ->minItems(1)
            ->maxItems(6)
            ->reorderable()
            ->schema([
                TextInput::make('label')
                    ->required()
                    ->maxLength(40),
                TextInput::make('minimum')
                    ->label('Min %')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->required(),
                TextInput::make('maximum')
                    ->label('Max %')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->required(),
                ColorPicker::make('color')
                    ->regex('/^#[0-9a-fA-F]{6}$/')
                    ->required(),
            ])
            ->columns(4)
            ->columnSpanFull()
            ->helperText('These thresholds color every percentage value in the Stenter widget, including current shift and daily efficiencies.');
    }

    private function compressorShiftsRepeater(): Repeater
    {
        return Repeater::make('shifts')
            ->label('Shifts (UTC)')
            ->default(CompressorUtilizationConfig::defaultShifts())
            ->minItems(1)
            ->maxItems(6)
            ->reorderable()
            ->schema([
                TextInput::make('label')
                    ->required()
                    ->maxLength(40),
                TextInput::make('start_time')
                    ->label('Start UTC')
                    ->placeholder('06:00')
                    ->regex('/^([01]\\d|2[0-3]):[0-5]\\d$/')
                    ->required(),
                TextInput::make('end_time')
                    ->label('End UTC')
                    ->placeholder('14:00')
                    ->regex('/^([01]\\d|2[0-3]):[0-5]\\d$/')
                    ->required(),
            ])
            ->columns(3)
            ->columnSpanFull()
            ->helperText('Store compressor shift windows in UTC. Runtime and idle time are calculated inside the active shift.');
    }

    private function compressorPercentageThresholdsRepeater(): Repeater
    {
        return Repeater::make('percentage_thresholds')
            ->label('Utilization thresholds')
            ->default(CompressorUtilizationConfig::defaultPercentageThresholds())
            ->minItems(1)
            ->maxItems(6)
            ->reorderable()
            ->schema([
                TextInput::make('label')
                    ->required()
                    ->maxLength(40),
                TextInput::make('minimum')
                    ->label('Min %')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->required(),
                TextInput::make('maximum')
                    ->label('Max %')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->required(),
                ColorPicker::make('color')
                    ->regex('/^#[0-9a-fA-F]{6}$/')
                    ->required(),
            ])
            ->columns(4)
            ->columnSpanFull()
            ->helperText('These thresholds color the current shift gauge and the last three day utilization rings.');
    }

    private function steamMeterShiftsRepeater(): Repeater
    {
        return Repeater::make('shifts')
            ->label('Shifts (UTC)')
            ->default(SteamMeterConfig::defaultShifts())
            ->minItems(1)
            ->maxItems(6)
            ->reorderable()
            ->schema([
                TextInput::make('label')
                    ->required()
                    ->maxLength(40),
                TextInput::make('start_time')
                    ->label('Start UTC')
                    ->placeholder('06:00')
                    ->regex('/^([01]\d|2[0-3]):[0-5]\d$/')
                    ->required(),
                TextInput::make('end_time')
                    ->label('End UTC')
                    ->placeholder('14:00')
                    ->regex('/^([01]\d|2[0-3]):[0-5]\d$/')
                    ->required(),
            ])
            ->columns(3)
            ->columnSpanFull()
            ->helperText('Store steam meter shift windows in UTC. The widget shows monthly, current shift, and previous shift consumption without a graph.');
    }

    private function statusSummaryRowsRepeater(IoTDashboard $dashboard): Repeater
    {
        return Repeater::make('rows')
            ->label('Rows')
            ->default([['tiles' => []]])
            ->minItems(1)
            ->maxItems(8)
            ->reorderable()
            ->schema($this->statusSummaryRowFields($dashboard))
            ->helperText('Add rows and configure tiles inside each row.')
            ->columnSpanFull();
    }

    /**
     * @return array<int, Component>
     */
    private function statusSummaryTileFields(IoTDashboard $dashboard): array
    {
        return [
            Hidden::make('source.type')->default('latest_parameter'),
            Select::make('source.parameter_key')
                ->label('Metric parameter')
                ->options(function (Get $get): array {
                    $topicId = $this->resolveStatusSummaryTopicId($get);

                    return $this->optionsService->statusSummaryMetricOptions($topicId);
                })
                ->searchable()
                ->required(),
            Repeater::make('threshold_ranges')
                ->label('Threshold colors')
                ->default([])
                ->table([
                    TableColumn::make('Min'),
                    TableColumn::make('Max'),
                    TableColumn::make('Color')->markAsRequired()->width('8rem'),
                ])
                ->schema([
                    TextInput::make('from')
                        ->numeric()
                        ->placeholder('Min')
                        ->hiddenLabel(),
                    TextInput::make('to')
                        ->numeric()
                        ->placeholder('Max')
                        ->hiddenLabel(),
                    ColorPicker::make('color')
                        ->required()
                        ->hiddenLabel(),
                ])
                ->helperText('The first matching range determines the tile color. Leave min or max empty for open-ended ranges.')
                ->columnSpanFull(),
        ];
    }

    private function resolveStatusSummaryTopicId(Get $get): mixed
    {
        return $get('device_channel_id')
            ?? $get('../../device_channel_id')
            ?? $get('../../../device_channel_id')
            ?? $get('../../../../device_channel_id');
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function statusSummaryTilePreviewLabel(array $state): string
    {
        $parameterKey = data_get($state, 'source.parameter_key');

        if (is_string($parameterKey) && trim($parameterKey) !== '') {
            return trim($parameterKey);
        }

        return 'Metric';
    }
}
