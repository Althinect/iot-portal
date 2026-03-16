<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\DeviceManagement\Devices;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Filament\Actions\DeviceManagement\SimulatePublishingActions;
use App\Filament\Admin\Resources\DeviceManagement\Devices\RelationManagers\TelemetryLogsRelationManager;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class DeviceResource extends Resource
{
    protected static ?string $model = Device::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static ?string $tenantOwnershipRelationshipName = 'organization';

    public static function getNavigationGroup(): ?string
    {
        return __('IoT Management');
    }

    public static function getNavigationLabel(): string
    {
        return __('Devices');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Identity')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('uuid')
                            ->label('UUID')
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn ($record) => $record !== null),

                        TextInput::make('external_id')
                            ->label('External ID')
                            ->maxLength(255),
                    ])
                    ->columnSpan(2),

                Section::make('Configuration')
                    ->schema([
                        Select::make('device_type_id')
                            ->label('Device Type')
                            ->relationship('deviceType', 'name')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->live(),

                        Select::make('device_schema_version_id')
                            ->label('Schema Version')
                            ->options(fn (Get $get) => DeviceSchemaVersion::query()
                                ->whereHas('schema', fn ($query) => $query->where('device_type_id', $get('device_type_id')))
                                ->where('status', 'active')
                                ->pluck('version', 'id')
                                ->map(fn (mixed $version): string => 'Version '.(is_scalar($version) ? (string) $version : ''))
                                ->toArray())
                            ->required()
                            ->searchable()
                            ->preload()
                            ->disabled(fn (Get $get) => ! $get('device_type_id')),
                    ])
                    ->columnSpan(1),

                Section::make('Status')
                    ->schema([
                        Toggle::make('is_active')
                            ->label('Is Active')
                            ->default(true),

                        Placeholder::make('effective_connection_state')
                            ->label('Connection State')
                            ->content(fn (?Device $record): string => $record ? Str::headline($record->effectiveConnectionState()) : 'Unknown'),

                        TextInput::make('presence_timeout_seconds')
                            ->label('Presence Timeout (seconds)')
                            ->numeric()
                            ->integer()
                            ->minValue(60)
                            ->maxValue(86400)
                            ->live()
                            ->placeholder('Global fallback (300 seconds)')
                            ->dehydrateStateUsing(fn (mixed $state): ?int => is_numeric($state) ? (int) $state : null)
                            ->helperText('Blank uses the global fallback of 300 seconds.'),

                        Placeholder::make('effective_presence_timeout')
                            ->label('Effective Timeout')
                            ->content(function (Get $get, ?Device $record): string {
                                $configuredTimeout = config('iot.presence.heartbeat_timeout_seconds', 300);
                                $fallbackTimeoutSeconds = is_numeric($configuredTimeout) && (int) $configuredTimeout > 0
                                    ? (int) $configuredTimeout
                                    : 300;
                                $override = $get('presence_timeout_seconds');
                                $effectiveTimeoutSeconds = is_numeric($override) && (int) $override >= 60
                                    ? (int) $override
                                    : ($record?->presenceTimeoutSeconds() ?? $fallbackTimeoutSeconds);

                                return "{$effectiveTimeoutSeconds} seconds";
                            }),

                        TextInput::make('last_seen_at')
                            ->label('Last Seen At')
                            ->disabled()
                            ->placeholder('Never'),
                    ])
                    ->columns(2)
                    ->columnSpan(2),

                Section::make('Metadata')
                    ->schema([
                        KeyValue::make('metadata')
                            ->columnSpanFull(),
                    ])
                    ->columnSpan(1),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Device Details')
                    ->schema([
                        TextEntry::make('name')
                            ->weight('bold'),

                        TextEntry::make('uuid')
                            ->label('UUID')
                            ->copyable(),

                        TextEntry::make('external_id')
                            ->label('External ID'),

                        TextEntry::make('deviceType.name')
                            ->label('Device Type'),

                        TextEntry::make('schemaVersion.version')
                            ->label('Schema Version')
                            ->formatStateUsing(fn ($state) => "Version {$state}"),
                    ])
                    ->columns(2)
                    ->columnSpan(2),

                Section::make('Status')
                    ->schema([
                        TextEntry::make('is_active')
                            ->label('Active')
                            ->badge()
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Active' : 'Inactive')
                            ->color(fn (bool $state): string => $state ? 'success' : 'gray'),

                        TextEntry::make('effective_connection_state')
                            ->label('Connection State')
                            ->state(fn (Device $record): string => $record->effectiveConnectionState())
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => Str::headline($state))
                            ->color(fn (string $state): string => match ($state) {
                                'online' => 'success',
                                'offline' => 'danger',
                                default => 'gray',
                            }),

                        TextEntry::make('effective_presence_timeout')
                            ->label('Effective Timeout')
                            ->state(fn (Device $record): string => "{$record->presenceTimeoutSeconds()} seconds"),

                        TextEntry::make('last_seen_at')
                            ->label('Last Seen')
                            ->since()
                            ->placeholder('Never'),

                        TextEntry::make('offline_deadline_at')
                            ->label('Offline Deadline')
                            ->state(fn (Device $record) => $record->resolvedOfflineDeadlineAt())
                            ->dateTime()
                            ->placeholder('Pending first signal'),
                    ])
                    ->columnSpan(1),

                Section::make('Metadata')
                    ->schema([
                        KeyValueEntry::make('metadata')
                            ->state(fn (Device $record): array => self::normalizeMetadataForDisplay($record->getAttribute('metadata')))
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),

                Section::make('Command Payload Samples')
                    ->description('Example JSON payloads this device expects on command (subscribe) topics, using schema defaults.')
                    ->schema([
                        KeyValueEntry::make('command_payload_samples')
                            ->valueLabel('JSON')
                            ->columnSpanFull()
                            ->state(function (Device $record): array {
                                $record->loadMissing('schemaVersion.topics.parameters');

                                $topics = $record->schemaVersion?->topics
                                    ?->filter(fn (SchemaVersionTopic $topic): bool => $topic->isSubscribe())
                                    ->sortBy('sequence');

                                if (! $topics || $topics->isEmpty()) {
                                    return [];
                                }

                                return $topics->mapWithKeys(function (SchemaVersionTopic $topic): array {
                                    $template = $topic->buildCommandPayloadTemplate();

                                    return [
                                        $topic->key => json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
                                    ];
                                })->all();
                            })
                            ->visible(fn (Device $record): bool => $record->getAttribute('device_schema_version_id') !== null),
                    ])
                    ->columnSpanFull(),

                Section::make('MQTT Publish Payload Samples')
                    ->description('Example topics + JSON payload structure the device should publish (Device → Platform). Copy and paste into your MQTT client.')
                    ->schema([
                        TextEntry::make('mqtt_publish_payload_samples')
                            ->label('Publish Samples')
                            ->copyable()
                            ->placeholder('—')
                            ->extraAttributes(['class' => 'font-mono whitespace-pre-wrap'])
                            ->state(function (Device $record): string {
                                $record->loadMissing('schemaVersion.topics.parameters', 'deviceType');

                                $topics = $record->schemaVersion?->topics
                                    ?->filter(fn (SchemaVersionTopic $topic): bool => $topic->isPublish())
                                    ->sortBy('sequence');

                                if (! $topics || $topics->isEmpty()) {
                                    return '';
                                }

                                $samples = $topics->map(function (SchemaVersionTopic $topic) use ($record): string {
                                    $resolvedTopic = $topic->resolvedTopic($record);
                                    $template = $topic->buildPublishPayloadTemplate();
                                    $json = json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';

                                    $qos = $topic->qos ?? 0;
                                    $retain = $topic->retain ? 'true' : 'false';

                                    return "Topic: {$resolvedTopic}\nQoS: {$qos}\nRetain: {$retain}\nPayload:\n{$json}";
                                })->all();

                                return implode("\n\n", $samples);
                            })
                            ->visible(fn (Device $record): bool => $record->getAttribute('device_schema_version_id') !== null),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return array<string, string>
     */
    private static function normalizeMetadataForDisplay(mixed $metadata): array
    {
        if (! is_array($metadata)) {
            return [];
        }

        return collect($metadata)
            ->map(fn (mixed $value): string => match (true) {
                is_array($value) => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]',
                is_bool($value) => $value ? 'true' : 'false',
                $value === null => 'null',
                is_scalar($value) => (string) $value,
                default => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: get_debug_type($value),
            })
            ->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('deviceType.name')
                    ->label('Type')
                    ->searchable()
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),

                IconColumn::make('effective_connection_state')
                    ->label('Status')
                    ->state(fn (Device $record): string => $record->effectiveConnectionState())
                    ->icon(fn (?string $state): Heroicon => match ($state) {
                        'online' => Heroicon::Wifi,
                        'offline' => Heroicon::SignalSlash,
                        default => Heroicon::QuestionMarkCircle,
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'online' => 'success',
                        'offline' => 'danger',
                        default => 'gray',
                    })
                    ->tooltip(fn (Device $record): string => $record->presenceStatusTooltip()),

                TextColumn::make('last_seen_at')
                    ->label('Last Seen')
                    ->since()
                    ->sortable()
                    ->placeholder('Never'),
            ])
            ->filters([
                SelectFilter::make('deviceType')
                    ->relationship('deviceType', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('effective_connection_state')
                    ->label('Status')
                    ->options([
                        'online' => 'Online',
                        'offline' => 'Offline',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if (! is_string($value) || ! in_array($value, ['online', 'offline'], true)) {
                            return $query;
                        }

                        return $query->whereEffectiveConnectionState($value);
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                SimulatePublishingActions::recordAction(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    SimulatePublishingActions::bulkAction(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            TelemetryLogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDevices::route('/'),
            'create' => Pages\CreateDevice::route('/create'),
            'view' => Pages\ViewDevice::route('/{record}'),
            'edit' => Pages\EditDevice::route('/{record}/edit'),
        ];
    }
}
