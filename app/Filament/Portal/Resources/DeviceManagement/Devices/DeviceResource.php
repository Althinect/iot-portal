<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\DeviceManagement\Devices;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\DTO\ChannelDefinition;
use App\Domain\DeviceProfile\DTO\DeviceProfileContract;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Services\DeviceProfileContractResolver;
use App\Filament\Admin\Resources\DeviceManagement\Devices\RelationManagers\TelemetryLogsRelationManager;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
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
                        Select::make('device_profile_version_id')
                            ->label('Device Profile Version')
                            ->options(fn (Get $get): array => self::profileVersionOptions($get))
                            ->required()
                            ->searchable()
                            ->preload()
                            ->live(),
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

                        TextEntry::make('profileVersion.profile.name')
                            ->label('Profile'),

                        TextEntry::make('profileVersion.version')
                            ->label('Profile Version')
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
                    ->description('Example JSON payloads this device expects on command channels, using profile defaults.')
                    ->schema([
                        KeyValueEntry::make('command_payload_samples')
                            ->valueLabel('JSON')
                            ->columnSpanFull()
                            ->state(function (Device $record): array {
                                $contract = self::contractFor($record);

                                if ($contract === null) {
                                    return [];
                                }

                                return $contract->commandChannels()
                                    ->sortBy('sequence')
                                    ->mapWithKeys(function (ChannelDefinition $channel): array {
                                        $template = $channel->buildCommandPayloadTemplate();

                                        return [
                                            $channel->key => json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
                                        ];
                                    })
                                    ->all();
                            })
                            ->visible(fn (Device $record): bool => $record->getAttribute('device_profile_version_id') !== null),
                    ])
                    ->columnSpanFull(),

                Section::make('Publish Payload Samples')
                    ->description('Example transport addresses and JSON payload structure the device should publish.')
                    ->schema([
                        TextEntry::make('mqtt_publish_payload_samples')
                            ->label('Publish Samples')
                            ->copyable()
                            ->placeholder('—')
                            ->extraAttributes(['class' => 'font-mono whitespace-pre-wrap'])
                            ->state(function (Device $record): string {
                                $contract = self::contractFor($record);

                                if ($contract === null) {
                                    return '';
                                }

                                $identifier = self::deviceIdentifier($record);
                                $samples = $contract->publishChannels()
                                    ->sortBy('sequence')
                                    ->map(function (ChannelDefinition $channel) use ($contract, $identifier): string {
                                        $resolvedAddress = $channel->resolvedAddress($identifier, $contract->protocolConfig);
                                        $template = $channel->buildPublishPayloadTemplate();
                                        $json = json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';

                                        $qos = $channel->qos;
                                        $retain = $channel->retain ? 'true' : 'false';

                                        return "Address: {$resolvedAddress}\nQoS: {$qos}\nRetain: {$retain}\nPayload:\n{$json}";
                                    })
                                    ->all();

                                return implode("\n\n", $samples);
                            })
                            ->visible(fn (Device $record): bool => $record->getAttribute('device_profile_version_id') !== null),
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

    /**
     * @return array<string, array<int, string>>
     */
    private static function profileVersionOptions(Get $get): array
    {
        $query = DeviceProfileVersion::query()
            ->with('profile')
            ->where('status', 'active')
            ->whereHas('profile', function (Builder $profileQuery): void {
                $tenant = Filament::getTenant();

                if ($tenant !== null && is_numeric($tenant->getKey())) {
                    $profileQuery
                        ->whereNull('organization_id')
                        ->orWhere('organization_id', (int) $tenant->getKey());

                    return;
                }

                $profileQuery->whereNull('organization_id');
            })
            ->orderByDesc('version');

        return $query
            ->get(['id', 'device_profile_id', 'version', 'protocol'])
            ->groupBy(fn (DeviceProfileVersion $version): string => $version->profile?->name ?? 'Profile')
            ->map(fn ($versions): array => $versions
                ->mapWithKeys(fn (DeviceProfileVersion $version): array => [
                    (int) $version->id => "v{$version->version} · {$version->protocol->getLabel()}",
                ])
                ->all())
            ->all();
    }

    private static function contractFor(Device $record): ?DeviceProfileContract
    {
        $record->loadMissing('profileVersion');

        if (! $record->profileVersion instanceof DeviceProfileVersion) {
            return null;
        }

        return app(DeviceProfileContractResolver::class)->resolve($record->profileVersion);
    }

    private static function deviceIdentifier(Device $record): string
    {
        $externalId = $record->getAttribute('external_id');

        return is_string($externalId) && trim($externalId) !== '' ? $externalId : (string) $record->uuid;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('profileVersion.profile.name')
                    ->label('Profile')
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
                SelectFilter::make('device_profile_version_id')
                    ->label('Profile Version')
                    ->relationship('profileVersion', 'version')
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
                Action::make('controlDashboard')
                    ->label('Control Dashboard')
                    ->icon(Heroicon::OutlinedCommandLine)
                    ->url(fn (Device $record): string => self::getUrl('control-dashboard', ['record' => $record]))
                    ->visible(fn (Device $record): bool => $record->canBeControlled()),
                ViewAction::make(),
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
            'view' => Pages\ViewDevice::route('/{record}'),
            'control-dashboard' => Pages\DeviceControlDashboard::route('/{record}/control-dashboard'),
        ];
    }
}
