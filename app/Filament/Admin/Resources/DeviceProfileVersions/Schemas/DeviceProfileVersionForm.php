<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DeviceProfileVersions\Schemas;

use App\Domain\DeviceProfile\Enums\Protocol;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class DeviceProfileVersionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Tabs::make('Profile version editor')
                    ->persistTabInQueryString('version-tab')
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make('Contract')
                            ->schema([
                                Section::make('Version')
                                    ->schema([
                                        Select::make('device_profile_id')
                                            ->label('Device type / profile')
                                            ->relationship('profile', 'name')
                                            ->searchable()
                                            ->preload()
                                            ->required()
                                            ->disabled(fn (?DeviceProfileVersion $record): bool => self::contractLocked($record)),
                                        TextInput::make('version')
                                            ->numeric()
                                            ->integer()
                                            ->minValue(1)
                                            ->required()
                                            ->disabled(fn (?DeviceProfileVersion $record): bool => self::contractLocked($record)),
                                        Select::make('status')
                                            ->options([
                                                DeviceProfileVersion::STATUS_DRAFT => 'Draft',
                                                DeviceProfileVersion::STATUS_ACTIVE => 'Active',
                                                DeviceProfileVersion::STATUS_SUPERSEDED => 'Superseded',
                                            ])
                                            ->default(DeviceProfileVersion::STATUS_DRAFT)
                                            ->disabled()
                                            ->dehydrated(false),
                                        Select::make('protocol')
                                            ->options(Protocol::class)
                                            ->default(Protocol::Mqtt->value)
                                            ->required()
                                            ->live()
                                            ->disabled(fn (?DeviceProfileVersion $record): bool => self::contractLocked($record)),
                                        Textarea::make('notes')
                                            ->label('Change summary')
                                            ->rows(3)
                                            ->columnSpanFull(),
                                    ])
                                    ->columns(2),
                                Section::make('Protocol config')
                                    ->schema(self::protocolFields())
                                    ->columns(2),
                            ]),
                        Tab::make('Channels')
                            ->schema([
                                Section::make('Channels')
                                    ->schema([
                                        TextEntry::make('channels_summary')
                                            ->label('Configured channels')
                                            ->state(fn (?DeviceProfileVersion $record): string => $record instanceof DeviceProfileVersion ? (string) $record->channels()->count() : '0'),
                                        TextEntry::make('channels_hint')
                                            ->label('Editor')
                                            ->state('Use the Channels relation manager below to add publish, subscribe, telemetry, state, and command channels.'),
                                    ])
                                    ->columns(2),
                            ]),
                        Tab::make('Derived Parameters')
                            ->schema([
                                Section::make('Derived Parameters')
                                    ->schema([
                                        TextEntry::make('derived_parameters_summary')
                                            ->label('Configured derived parameters')
                                            ->state(fn (?DeviceProfileVersion $record): string => $record instanceof DeviceProfileVersion ? (string) $record->derivedParameters()->count() : '0'),
                                        TextEntry::make('derived_parameters_hint')
                                            ->label('Editor')
                                            ->state('Use the Derived Parameters relation manager below for JsonLogic expressions and dependencies.'),
                                    ])
                                    ->columns(2),
                            ]),
                        Tab::make('Firmware / Ingestion')
                            ->schema([
                                CodeEditor::make('firmware_template')
                                    ->language(Language::Cpp)
                                    ->disabled(fn (?DeviceProfileVersion $record): bool => self::contractLocked($record))
                                    ->columnSpanFull(),
                                CodeEditor::make('ingestion_config_json')
                                    ->label('Ingestion config JSON')
                                    ->language(Language::Json)
                                    ->rules(['nullable', 'json'])
                                    ->disabled(fn (?DeviceProfileVersion $record): bool => self::contractLocked($record))
                                    ->columnSpanFull(),
                                CodeEditor::make('virtual_standard_profile_json')
                                    ->label('Virtual standard profile JSON')
                                    ->language(Language::Json)
                                    ->rules(['nullable', 'json'])
                                    ->disabled(fn (?DeviceProfileVersion $record): bool => self::contractLocked($record))
                                    ->columnSpanFull(),
                            ]),
                        Tab::make('Links')
                            ->schema([
                                Section::make('Channel Links')
                                    ->schema([
                                        TextEntry::make('channel_links_summary')
                                            ->label('Configured feedback links')
                                            ->state(fn (?DeviceProfileVersion $record): string => $record instanceof DeviceProfileVersion ? (string) $record->channelLinks()->count() : '0'),
                                        TextEntry::make('channel_links_hint')
                                            ->label('Editor')
                                            ->state('Use the Channel Links relation manager below to connect command channels to state or acknowledgement feedback channels.'),
                                    ])
                                    ->columns(2),
                            ]),
                        Tab::make('Preview')
                            ->schema([
                                Section::make('Contract preview')
                                    ->schema([
                                        TextEntry::make('profile.name')
                                            ->label('Profile'),
                                        TextEntry::make('version')
                                            ->formatStateUsing(fn (mixed $state): string => is_scalar($state) ? "v{$state}" : 'Draft'),
                                        TextEntry::make('status')
                                            ->badge(),
                                        TextEntry::make('protocol')
                                            ->badge(),
                                    ])
                                    ->columns(4),
                            ]),
                    ]),
            ]);
    }

    /**
     * @return array<int, mixed>
     */
    private static function protocolFields(): array
    {
        return [
            TextInput::make('mqtt_broker_host')
                ->label('MQTT broker host')
                ->default((string) config('iot.mqtt.host', '127.0.0.1'))
                ->required(fn (Get $get): bool => $get('protocol') === Protocol::Mqtt->value)
                ->visible(fn (Get $get): bool => $get('protocol') === Protocol::Mqtt->value)
                ->disabled(fn (?DeviceProfileVersion $record): bool => self::contractLocked($record)),
            TextInput::make('mqtt_broker_port')
                ->label('MQTT broker port')
                ->numeric()
                ->integer()
                ->minValue(1)
                ->maxValue(65535)
                ->default((int) config('iot.mqtt.port', 1883))
                ->required(fn (Get $get): bool => $get('protocol') === Protocol::Mqtt->value)
                ->visible(fn (Get $get): bool => $get('protocol') === Protocol::Mqtt->value)
                ->disabled(fn (?DeviceProfileVersion $record): bool => self::contractLocked($record)),
            TextInput::make('mqtt_base_topic')
                ->label('Base topic')
                ->default('device')
                ->required(fn (Get $get): bool => $get('protocol') === Protocol::Mqtt->value)
                ->visible(fn (Get $get): bool => $get('protocol') === Protocol::Mqtt->value)
                ->disabled(fn (?DeviceProfileVersion $record): bool => self::contractLocked($record)),
            Toggle::make('mqtt_use_tls')
                ->label('Use TLS')
                ->visible(fn (Get $get): bool => $get('protocol') === Protocol::Mqtt->value)
                ->disabled(fn (?DeviceProfileVersion $record): bool => self::contractLocked($record)),
            TextInput::make('http_base_url')
                ->label('HTTP base URL')
                ->url()
                ->required(fn (Get $get): bool => $get('protocol') === Protocol::Http->value)
                ->visible(fn (Get $get): bool => $get('protocol') === Protocol::Http->value)
                ->disabled(fn (?DeviceProfileVersion $record): bool => self::contractLocked($record)),
            TextInput::make('http_telemetry_endpoint')
                ->label('Telemetry endpoint')
                ->default('/telemetry')
                ->visible(fn (Get $get): bool => $get('protocol') === Protocol::Http->value)
                ->disabled(fn (?DeviceProfileVersion $record): bool => self::contractLocked($record)),
            Select::make('http_method')
                ->label('HTTP method')
                ->options([
                    'GET' => 'GET',
                    'POST' => 'POST',
                    'PUT' => 'PUT',
                    'PATCH' => 'PATCH',
                ])
                ->default('POST')
                ->visible(fn (Get $get): bool => $get('protocol') === Protocol::Http->value)
                ->disabled(fn (?DeviceProfileVersion $record): bool => self::contractLocked($record)),
        ];
    }

    private static function contractLocked(?DeviceProfileVersion $record): bool
    {
        return $record instanceof DeviceProfileVersion && ! $record->canEditContract();
    }
}
