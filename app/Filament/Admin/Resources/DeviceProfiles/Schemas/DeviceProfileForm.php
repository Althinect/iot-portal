<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DeviceProfiles\Schemas;

use App\Domain\DeviceProfile\Enums\ChannelDirection;
use App\Domain\DeviceProfile\Enums\ChannelPurpose;
use App\Domain\DeviceProfile\Enums\ChannelTransport;
use App\Domain\DeviceProfile\Enums\ParameterCategory;
use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Enums\Protocol;
use App\Filament\Admin\Support\JsonCodeEditorState;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
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
                    Step::make('Protocol & Channels')
                        ->schema([
                            ...self::protocolFields(),
                            self::starterChannelsRepeater(),
                        ])
                        ->columns(2),
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
                ->live()
                ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                    $starterChannels = $get('starter_channels');

                    if (! self::starterChannelsUseProtocolDefaults($starterChannels, $get('http_telemetry_endpoint'))) {
                        return;
                    }

                    $set('starter_channels', self::defaultStarterChannelsForProtocol(
                        $state,
                        $get('http_telemetry_endpoint'),
                        $get('http_method'),
                    ));
                }),
            TextInput::make('mqtt_broker_host')
                ->label('MQTT broker host')
                ->default((string) config('iot.mqtt.host', '127.0.0.1'))
                ->required(fn (Get $get): bool => self::protocolFromState($get('protocol')) === Protocol::Mqtt)
                ->visible(fn (Get $get): bool => self::protocolFromState($get('protocol')) === Protocol::Mqtt)
                ->dehydratedWhenHidden(),
            TextInput::make('mqtt_broker_port')
                ->label('MQTT broker port')
                ->numeric()
                ->integer()
                ->minValue(1)
                ->maxValue(65535)
                ->default((int) config('iot.mqtt.port', 1883))
                ->required(fn (Get $get): bool => self::protocolFromState($get('protocol')) === Protocol::Mqtt)
                ->visible(fn (Get $get): bool => self::protocolFromState($get('protocol')) === Protocol::Mqtt)
                ->dehydratedWhenHidden(),
            TextInput::make('mqtt_base_topic')
                ->label('Base topic')
                ->default('device')
                ->required(fn (Get $get): bool => self::protocolFromState($get('protocol')) === Protocol::Mqtt)
                ->visible(fn (Get $get): bool => self::protocolFromState($get('protocol')) === Protocol::Mqtt)
                ->dehydratedWhenHidden(),
            Toggle::make('mqtt_use_tls')
                ->label('Use TLS')
                ->default(false)
                ->visible(fn (Get $get): bool => self::protocolFromState($get('protocol')) === Protocol::Mqtt)
                ->dehydratedWhenHidden(),
            TextInput::make('http_base_url')
                ->label('Platform ingestion base URL')
                ->url()
                ->helperText('Base platform URL where devices submit HTTP telemetry.')
                ->required(fn (Get $get): bool => self::protocolFromState($get('protocol')) === Protocol::Http)
                ->visible(fn (Get $get): bool => self::protocolFromState($get('protocol')) === Protocol::Http)
                ->dehydratedWhenHidden(),
            TextInput::make('http_telemetry_endpoint')
                ->label('Telemetry webhook path')
                ->default('/telemetry')
                ->helperText('Device-to-platform path used for the HTTP telemetry channel.')
                ->live(onBlur: true)
                ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                    if (self::protocolFromState($get('protocol')) !== Protocol::Http || ! self::starterChannelsUseProtocolDefaults($get('starter_channels'), $state)) {
                        return;
                    }

                    $set('starter_channels', self::defaultStarterChannelsForProtocol(
                        Protocol::Http->value,
                        $state,
                        $get('http_method'),
                    ));
                })
                ->visible(fn (Get $get): bool => self::protocolFromState($get('protocol')) === Protocol::Http)
                ->dehydratedWhenHidden(),
            Select::make('http_method')
                ->label('Webhook method')
                ->options([
                    'GET' => 'GET',
                    'POST' => 'POST',
                    'PUT' => 'PUT',
                    'PATCH' => 'PATCH',
                ])
                ->default('POST')
                ->live()
                ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                    if (self::protocolFromState($get('protocol')) !== Protocol::Http || ! self::starterChannelsUseProtocolDefaults($get('starter_channels'), $get('http_telemetry_endpoint'))) {
                        return;
                    }

                    $set('starter_channels', self::defaultStarterChannelsForProtocol(
                        Protocol::Http->value,
                        $get('http_telemetry_endpoint'),
                        $state,
                    ));
                })
                ->visible(fn (Get $get): bool => self::protocolFromState($get('protocol')) === Protocol::Http)
                ->dehydratedWhenHidden(),
        ];
    }

    private static function starterChannelsRepeater(): Repeater
    {
        return Repeater::make('starter_channels')
            ->label(fn (Get $get): string => self::protocolFromState($get('protocol')) === Protocol::Http ? 'Telemetry webhook channel' : 'Channels')
            ->default(self::defaultStarterChannelsForProtocol(Protocol::Mqtt->value))
            ->schema([
                TextInput::make('key')
                    ->required()
                    ->maxLength(100)
                    ->regex('/^[a-z0-9_-]+$/')
                    ->visible(fn (Get $get): bool => self::protocolFromState($get('../../protocol')) === Protocol::Mqtt)
                    ->dehydratedWhenHidden(),
                TextInput::make('label')
                    ->required()
                    ->maxLength(255)
                    ->visible(fn (Get $get): bool => self::protocolFromState($get('../../protocol')) === Protocol::Mqtt)
                    ->dehydratedWhenHidden(),
                Select::make('direction')
                    ->options(ChannelDirection::class)
                    ->required()
                    ->live()
                    ->visible(fn (Get $get): bool => self::protocolFromState($get('../../protocol')) === Protocol::Mqtt)
                    ->dehydratedWhenHidden(),
                Select::make('purpose')
                    ->options(ChannelPurpose::class)
                    ->default(ChannelPurpose::Telemetry->value)
                    ->visible(fn (Get $get): bool => self::protocolFromState($get('../../protocol')) === Protocol::Mqtt)
                    ->dehydratedWhenHidden(),
                TextInput::make('address')
                    ->label('Topic suffix')
                    ->required()
                    ->maxLength(255)
                    ->helperText('MQTT topic suffix resolved under the profile base topic.')
                    ->visible(fn (Get $get): bool => self::protocolFromState($get('../../protocol')) === Protocol::Mqtt)
                    ->dehydratedWhenHidden(),
                TextInput::make('qos')
                    ->label('QoS')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->maxValue(2)
                    ->default(1)
                    ->visible(fn (Get $get): bool => self::protocolFromState($get('../../protocol')) === Protocol::Mqtt),
                Toggle::make('retain')
                    ->default(false)
                    ->visible(fn (Get $get): bool => self::protocolFromState($get('../../protocol')) === Protocol::Mqtt),
                Repeater::make('parameters')
                    ->columnSpanFull()
                    ->defaultItems(0)
                    ->itemLabel(fn (array $state): ?string => $state['label'] ?? $state['key'] ?? null)
                    ->schema([
                        TextInput::make('key')
                            ->required()
                            ->maxLength(100)
                            ->regex('/^[a-z0-9_-]+$/')
                            ->columnSpan(4),
                        TextInput::make('label')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(4),
                        TextInput::make('json_path')
                            ->label('JSON path')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('status.temperature')
                            ->columnSpan(4),
                        Select::make('type')
                            ->options(ParameterDataType::class)
                            ->default(ParameterDataType::Decimal->value)
                            ->required()
                            ->columnSpan(2),
                        Select::make('category')
                            ->options(ParameterCategory::class)
                            ->default(ParameterCategory::Measurement->value)
                            ->required()
                            ->columnSpan(2),
                        TextInput::make('unit')
                            ->maxLength(50)
                            ->columnSpan(2),
                        Toggle::make('required')
                            ->default(false)
                            ->columnSpan(2),
                        Toggle::make('is_critical')
                            ->label('Critical')
                            ->default(false)
                            ->columnSpan(2),
                        Section::make('Advanced mutation')
                            ->schema([
                                CodeEditor::make('mutation_expression')
                                    ->label('Mutation expression')
                                    ->language(Language::Json)
                                    ->rules(['nullable', 'json'])
                                    ->formatStateUsing(fn (mixed $state): string => JsonCodeEditorState::encode($state))
                                    ->dehydrateStateUsing(fn (mixed $state): ?array => JsonCodeEditorState::decode($state))
                                    ->helperText('Optional JSON Logic. Use val for the extracted value; leave blank for no mutation.')
                                    ->columnSpanFull(),
                            ])
                            ->compact()
                            ->collapsible()
                            ->collapsed()
                            ->columnSpanFull(),
                    ])
                    ->columns(12)
                    ->collapsible()
                    ->cloneable()
                    ->reorderable(),
            ])
            ->columns(4)
            ->collapsible(fn (Get $get): bool => self::protocolFromState($get('protocol')) === Protocol::Mqtt)
            ->cloneable(fn (Get $get): bool => self::protocolFromState($get('protocol')) === Protocol::Mqtt)
            ->addable(fn (Get $get): bool => self::protocolFromState($get('protocol')) === Protocol::Mqtt)
            ->deletable(fn (Get $get): bool => self::protocolFromState($get('protocol')) === Protocol::Mqtt)
            ->reorderable(fn (Get $get): bool => self::protocolFromState($get('protocol')) === Protocol::Mqtt)
            ->minItems(1)
            ->columnSpanFull();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function defaultStarterChannelsForProtocol(
        mixed $protocol,
        mixed $httpTelemetryEndpoint = null,
        mixed $httpMethod = null,
    ): array {
        if (self::protocolFromState($protocol) === Protocol::Http) {
            $endpoint = is_string($httpTelemetryEndpoint) && trim($httpTelemetryEndpoint) !== ''
                ? trim($httpTelemetryEndpoint)
                : '/telemetry';
            $method = is_string($httpMethod) && in_array(strtoupper($httpMethod), ['GET', 'POST', 'PUT', 'PATCH'], true)
                ? strtoupper($httpMethod)
                : 'POST';

            return [
                [
                    'key' => 'telemetry',
                    'label' => 'Telemetry',
                    'direction' => ChannelDirection::Publish->value,
                    'purpose' => ChannelPurpose::Telemetry->value,
                    'transport' => ChannelTransport::Http->value,
                    'address' => str_starts_with($endpoint, '/') ? $endpoint : "/{$endpoint}",
                    'http_method' => $method,
                    'qos' => 0,
                    'retain' => false,
                    'parameters' => [],
                ],
            ];
        }

        return [
            [
                'key' => 'telemetry',
                'label' => 'Telemetry',
                'direction' => ChannelDirection::Publish->value,
                'purpose' => ChannelPurpose::Telemetry->value,
                'transport' => ChannelTransport::Mqtt->value,
                'address' => 'telemetry',
                'http_method' => '',
                'qos' => 1,
                'retain' => false,
                'parameters' => [],
            ],
        ];
    }

    private static function starterChannelsUseProtocolDefaults(mixed $starterChannels, mixed $httpTelemetryEndpoint = null): bool
    {
        if (! is_array($starterChannels) || count($starterChannels) !== 1) {
            return false;
        }

        $channel = array_values($starterChannels)[0] ?? null;

        if (! is_array($channel)) {
            return false;
        }

        $key = $channel['key'] ?? null;
        $parameters = $channel['parameters'] ?? [];
        $transport = $channel['transport'] ?? null;
        $transportValue = $transport instanceof ChannelTransport ? $transport->value : $transport;
        $direction = $channel['direction'] ?? null;
        $directionValue = $direction instanceof ChannelDirection ? $direction->value : $direction;
        $purpose = $channel['purpose'] ?? null;
        $purposeValue = $purpose instanceof ChannelPurpose ? $purpose->value : $purpose;

        $defaultAddresses = ['telemetry', '/telemetry'];

        if (is_string($httpTelemetryEndpoint) && trim($httpTelemetryEndpoint) !== '') {
            $endpoint = trim($httpTelemetryEndpoint);
            $defaultAddresses[] = str_starts_with($endpoint, '/') ? $endpoint : "/{$endpoint}";
        }

        return $key === 'telemetry'
            && ($channel['label'] ?? null) === 'Telemetry'
            && $directionValue === ChannelDirection::Publish->value
            && $purposeValue === ChannelPurpose::Telemetry->value
            && in_array($channel['address'] ?? null, $defaultAddresses, true)
            && is_array($parameters)
            && $parameters === []
            && in_array($transportValue, [ChannelTransport::Mqtt->value, ChannelTransport::Http->value, null], true);
    }

    private static function protocolFromState(mixed $protocol): Protocol
    {
        if ($protocol instanceof Protocol) {
            return $protocol;
        }

        if (is_string($protocol) && $protocol !== '') {
            return Protocol::from($protocol);
        }

        return Protocol::Mqtt;
    }
}
