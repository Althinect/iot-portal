<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DeviceProfiles\Schemas;

use App\Domain\DeviceProfile\Enums\ChannelDirection;
use App\Domain\DeviceProfile\Enums\ChannelPurpose;
use App\Domain\DeviceProfile\Enums\ChannelTransport;
use App\Domain\DeviceProfile\Enums\ParameterCategory;
use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Enums\Protocol;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class DeviceProfileForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Identity')
                    ->schema(self::identityFields())
                    ->columns(2)
                    ->hiddenOn('create'),

                Wizard::make([
                    Step::make('Identity')
                        ->schema(self::identityFields())
                        ->columns(2),
                    Step::make('Protocol')
                        ->schema(self::protocolFields())
                        ->columns(2),
                    Step::make('Starter channels')
                        ->schema([
                            self::starterChannelsRepeater(),
                        ]),
                    Step::make('Review')
                        ->schema([
                            Textarea::make('notes')
                                ->label('Change summary')
                                ->rows(4)
                                ->placeholder('Describe the initial profile contract.'),
                        ]),
                ])
                    ->columnSpanFull()
                    ->hiddenOn(['edit', 'view']),
            ]);
    }

    /**
     * @return array<int, mixed>
     */
    private static function identityFields(): array
    {
        return [
            Select::make('organization_id')
                ->label('Scope')
                ->relationship('organization', 'name')
                ->searchable()
                ->preload()
                ->placeholder('Global profile'),
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->live(onBlur: true)
                ->afterStateUpdated(function (Set $set, mixed $state): void {
                    if (is_string($state) && trim($state) !== '') {
                        $set('key', Str::slug($state, '_'));
                    }
                }),
            TextInput::make('key')
                ->required()
                ->maxLength(100)
                ->regex('/^[a-z0-9_:-]+$/')
                ->helperText('Stable type key used by device setup and virtual standards.'),
            KeyValue::make('tags')
                ->keyLabel('Tag')
                ->valueLabel('Value')
                ->columnSpanFull(),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private static function protocolFields(): array
    {
        return [
            Select::make('protocol')
                ->options(Protocol::class)
                ->default(Protocol::Mqtt->value)
                ->required()
                ->live(),
            TextInput::make('mqtt_broker_host')
                ->label('MQTT broker host')
                ->default((string) config('iot.mqtt.host', '127.0.0.1'))
                ->required(fn (Get $get): bool => $get('protocol') === Protocol::Mqtt->value)
                ->visible(fn (Get $get): bool => $get('protocol') === Protocol::Mqtt->value),
            TextInput::make('mqtt_broker_port')
                ->label('MQTT broker port')
                ->numeric()
                ->integer()
                ->minValue(1)
                ->maxValue(65535)
                ->default((int) config('iot.mqtt.port', 1883))
                ->required(fn (Get $get): bool => $get('protocol') === Protocol::Mqtt->value)
                ->visible(fn (Get $get): bool => $get('protocol') === Protocol::Mqtt->value),
            TextInput::make('mqtt_base_topic')
                ->label('Base topic')
                ->default('device')
                ->required(fn (Get $get): bool => $get('protocol') === Protocol::Mqtt->value)
                ->visible(fn (Get $get): bool => $get('protocol') === Protocol::Mqtt->value),
            Toggle::make('mqtt_use_tls')
                ->label('Use TLS')
                ->default(false)
                ->visible(fn (Get $get): bool => $get('protocol') === Protocol::Mqtt->value),
            TextInput::make('http_base_url')
                ->label('HTTP base URL')
                ->url()
                ->required(fn (Get $get): bool => $get('protocol') === Protocol::Http->value)
                ->visible(fn (Get $get): bool => $get('protocol') === Protocol::Http->value),
            TextInput::make('http_telemetry_endpoint')
                ->label('Telemetry endpoint')
                ->default('/telemetry')
                ->visible(fn (Get $get): bool => $get('protocol') === Protocol::Http->value),
            Select::make('http_method')
                ->label('HTTP method')
                ->options([
                    'GET' => 'GET',
                    'POST' => 'POST',
                    'PUT' => 'PUT',
                    'PATCH' => 'PATCH',
                ])
                ->default('POST')
                ->visible(fn (Get $get): bool => $get('protocol') === Protocol::Http->value),
        ];
    }

    private static function starterChannelsRepeater(): Repeater
    {
        return Repeater::make('starter_channels')
            ->label('Channels')
            ->default([
                [
                    'key' => 'telemetry',
                    'label' => 'Telemetry',
                    'direction' => ChannelDirection::Publish->value,
                    'purpose' => ChannelPurpose::Telemetry->value,
                    'transport' => ChannelTransport::Mqtt->value,
                    'address' => 'telemetry',
                    'qos' => 1,
                    'retain' => false,
                    'parameters' => [],
                ],
            ])
            ->schema([
                TextInput::make('key')
                    ->required()
                    ->maxLength(100)
                    ->regex('/^[a-z0-9_-]+$/'),
                TextInput::make('label')
                    ->required()
                    ->maxLength(255),
                Select::make('direction')
                    ->options(ChannelDirection::class)
                    ->required()
                    ->live(),
                Select::make('purpose')
                    ->options(ChannelPurpose::class),
                Select::make('transport')
                    ->options(ChannelTransport::class)
                    ->default(ChannelTransport::Mqtt->value)
                    ->required(),
                TextInput::make('address')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Topic suffix, HTTP path, or address template.'),
                TextInput::make('qos')
                    ->label('QoS')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->maxValue(2)
                    ->default(1),
                Toggle::make('retain')
                    ->default(false),
                Repeater::make('parameters')
                    ->schema([
                        TextInput::make('key')
                            ->required()
                            ->maxLength(100)
                            ->regex('/^[a-z0-9_-]+$/'),
                        TextInput::make('label')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('json_path')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('status.temperature'),
                        Select::make('type')
                            ->options(ParameterDataType::class)
                            ->default(ParameterDataType::Decimal->value)
                            ->required(),
                        Select::make('category')
                            ->options(ParameterCategory::class)
                            ->default(ParameterCategory::Measurement->value)
                            ->required(),
                        TextInput::make('unit')
                            ->maxLength(50),
                        Toggle::make('required')
                            ->default(false),
                        Toggle::make('is_critical')
                            ->label('Critical')
                            ->default(false),
                    ])
                    ->columns(4)
                    ->collapsible()
                    ->cloneable()
                    ->reorderable(),
            ])
            ->columns(4)
            ->collapsible()
            ->cloneable()
            ->reorderable()
            ->minItems(1)
            ->columnSpanFull();
    }
}
