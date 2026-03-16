<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\IoTDashboardSupport;

use App\Domain\IoTDashboard\Enums\WidgetType;
use App\Domain\IoTDashboard\Models\IoTDashboard;
use App\Domain\IoTDashboard\Widgets\BarChart\BarInterval;
use App\Domain\IoTDashboard\Widgets\GaugeChart\GaugeStyle;
use App\Domain\IoTDashboard\Widgets\StateCard\StateCardStyle;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;

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
            ...$this->baseScopeSchema($dashboard),
            Select::make('parameter_keys')
                ->label('Series parameters')
                ->multiple()
                ->options(fn (Get $get): array => $this->optionsService->parameterOptions($get('schema_version_topic_id')))
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
            ...$this->baseScopeSchema($dashboard),
            Select::make('parameter_key')
                ->label('Counter parameter')
                ->options(fn (Get $get): array => $this->optionsService->counterParameterOptions($get('schema_version_topic_id')))
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
            ...$this->baseScopeSchema($dashboard),
            Select::make('parameter_key')
                ->label('Gauge parameter')
                ->options(fn (Get $get): array => $this->optionsService->numericParameterOptions($get('schema_version_topic_id')))
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
    public function stateCardSchema(IoTDashboard $dashboard): array
    {
        return [
            TextInput::make('title')
                ->label('Widget title')
                ->required()
                ->default('State Card')
                ->maxLength(255),
            ...$this->baseScopeSchema($dashboard),
            Select::make('parameter_key')
                ->label('State parameter')
                ->options(fn (Get $get): array => $this->optionsService->stateParameterOptions($get('schema_version_topic_id')))
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
            ...$this->baseScopeSchema($dashboard),
            Select::make('parameter_key')
                ->label('State parameter')
                ->options(fn (Get $get): array => $this->optionsService->stateParameterOptions($get('schema_version_topic_id')))
                ->required(),
            ...$this->stateMappingsSchema(),
            ...$this->transportSchema(true, true, 10, 360, 240),
            ...$this->layoutSchema(),
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
            ...$this->baseScopeSchema($dashboard),
            Select::make('parameter_keys')
                ->label('Series parameters')
                ->multiple()
                ->options(fn (Get $get): array => $this->optionsService->parameterOptions($get('schema_version_topic_id')))
                ->visible(fn (Get $get): bool => $get('widget_type') === WidgetType::LineChart->value)
                ->required(fn (Get $get): bool => $get('widget_type') === WidgetType::LineChart->value),
            Select::make('parameter_key')
                ->label('Parameter')
                ->options(function (Get $get): array {
                    $topicId = $get('schema_version_topic_id');
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
            ...$this->transportSchema(true, true, 10, 120, 240),
            ...$this->layoutSchema(),
        ];
    }

    /**
     * @return array<int, Component>
     */
    private function baseScopeSchema(IoTDashboard $dashboard): array
    {
        return [
            Select::make('device_id')
                ->label('Device')
                ->options(fn (): array => $this->optionsService->deviceOptions($dashboard))
                ->searchable()
                ->required()
                ->live(),
            Select::make('schema_version_topic_id')
                ->label('Publish topic')
                ->options(fn (Get $get): array => $this->optionsService->topicOptions($dashboard, $get('device_id')))
                ->searchable()
                ->required()
                ->live(),
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
    ): array {
        return [
            Grid::make(2)
                ->schema([
                    Toggle::make('use_websocket')->default($defaultWebsocket),
                    Toggle::make('use_polling')->default($defaultPolling)->live(),
                ]),
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
                ]),
        ];
    }

    /**
     * @return array<int, Component>
     */
    private function layoutSchema(): array
    {
        return [
            Grid::make(2)
                ->schema([
                    Select::make('grid_columns')
                        ->label('Widget width')
                        ->options(fn (): array => $this->layoutService->gridColumnOptions())
                        ->default('6')
                        ->required(),
                    TextInput::make('card_height_px')
                        ->label('Initial height (px)')
                        ->integer()
                        ->minValue(260)
                        ->maxValue(900)
                        ->default(360)
                        ->required(),
                ]),
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
}
