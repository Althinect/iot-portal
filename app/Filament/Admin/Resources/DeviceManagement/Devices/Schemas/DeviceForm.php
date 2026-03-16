<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DeviceManagement\Devices\Schemas;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Models\DeviceType;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class DeviceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
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
                            ->maxLength(255)
                            ->helperText('Product serial number or hardware ID'),
                    ])
                    ->columns(2),

                Section::make('Configuration')
                    ->schema([
                        Select::make('organization_id')
                            ->relationship('organization', 'name')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->default(function (): ?int {
                                $organizationId = request()->query('organization_id');

                                if (is_numeric($organizationId)) {
                                    return (int) $organizationId;
                                }

                                $deviceTypeId = request()->query('device_type_id');

                                if (! is_numeric($deviceTypeId)) {
                                    return null;
                                }

                                $organization = DeviceType::query()
                                    ->whereKey((int) $deviceTypeId)
                                    ->value('organization_id');

                                return is_numeric($organization) ? (int) $organization : null;
                            })
                            ->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('device_type_id', null);
                                $set('device_schema_version_id', null);
                            }),

                        Select::make('device_type_id')
                            ->label('Device Type')
                            ->options(fn (Get $get): array => self::deviceTypeOptions($get))
                            ->required()
                            ->searchable()
                            ->default(function (): ?int {
                                $deviceTypeId = request()->query('device_type_id');

                                return is_numeric($deviceTypeId) ? (int) $deviceTypeId : null;
                            })
                            ->live()
                            ->helperText('Includes global catalog types plus organization-specific types for the selected organization.')
                            ->afterStateUpdated(function (Set $set, Get $get): void {
                                $defaultSchemaVersionId = self::defaultActiveSchemaVersionId($get('device_type_id'));
                                $set('device_schema_version_id', $defaultSchemaVersionId);
                            }),

                        Select::make('device_schema_version_id')
                            ->label('Schema Version')
                            ->options(fn (Get $get): array => self::schemaVersionOptions($get))
                            ->required()
                            ->searchable()
                            ->default(fn (): ?int => self::defaultActiveSchemaVersionId(request()->query('device_type_id')))
                            ->disabled(fn (Get $get) => ! $get('device_type_id'))
                            ->helperText('Only active schema versions for the selected device type are shown'),

                        Select::make('parent_device_id')
                            ->label('Parent Hub')
                            ->options(fn (Get $get, ?Device $record): array => self::parentDeviceOptions($get, $record))
                            ->searchable()
                            ->preload()
                            ->placeholder('No parent hub')
                            ->helperText('Assign this device to a hub for migration rehearsal visibility and health tracking.'),

                        Placeholder::make('onboarding_hint')
                            ->label('Onboarding Hint')
                            ->content(function (Get $get): string {
                                $deviceTypeId = $get('device_type_id');

                                if (! is_numeric($deviceTypeId)) {
                                    return 'Select an organization and device type to load active schema versions.';
                                }

                                $schemaOptions = self::schemaVersionOptions($get);

                                if ($schemaOptions === []) {
                                    return 'This device type has no active schema version yet. Create/activate a schema version before onboarding devices.';
                                }

                                return 'Schema version is preselected to the latest active version. You can still choose another active version.';
                            })
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

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
                    ->columns(2),

                Section::make('Metadata')
                    ->schema([
                        KeyValue::make('metadata')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * @return array<int, string>
     */
    private static function deviceTypeOptions(Get $get): array
    {
        $organizationId = $get('organization_id');

        $query = DeviceType::query()
            ->orderBy('name');

        if (is_numeric($organizationId)) {
            $query->where(function ($scope) use ($organizationId): void {
                $scope
                    ->whereNull('organization_id')
                    ->orWhere('organization_id', (int) $organizationId);
            });
        } else {
            $query->whereNull('organization_id');
        }

        return $query
            ->get(['id', 'name', 'organization_id'])
            ->mapWithKeys(function (DeviceType $deviceType): array {
                $scopeSuffix = $deviceType->organization_id === null ? ' (Global)' : '';

                return [
                    (int) $deviceType->id => "{$deviceType->name}{$scopeSuffix}",
                ];
            })
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private static function schemaVersionOptions(Get $get): array
    {
        $deviceTypeId = $get('device_type_id');

        if (! is_numeric($deviceTypeId)) {
            return [];
        }

        return DeviceSchemaVersion::query()
            ->with('schema')
            ->whereHas('schema', fn ($query) => $query->where('device_type_id', (int) $deviceTypeId))
            ->where('status', 'active')
            ->orderByDesc('version')
            ->get(['id', 'device_schema_id', 'version'])
            ->mapWithKeys(function (DeviceSchemaVersion $schemaVersion): array {
                $schemaName = data_get($schemaVersion, 'schema.name', 'Schema');
                $schemaName = is_string($schemaName) && trim($schemaName) !== '' ? $schemaName : 'Schema';
                $version = (string) $schemaVersion->version;

                return [
                    (int) $schemaVersion->id => "{$schemaName} · v{$version}",
                ];
            })
            ->all();
    }

    private static function defaultActiveSchemaVersionId(mixed $deviceTypeId): ?int
    {
        if (! is_numeric($deviceTypeId)) {
            return null;
        }

        $schemaVersion = DeviceSchemaVersion::query()
            ->whereHas('schema', fn ($query) => $query->where('device_type_id', (int) $deviceTypeId))
            ->where('status', 'active')
            ->orderByDesc('version')
            ->first(['id']);

        if ($schemaVersion === null) {
            return null;
        }

        return (int) $schemaVersion->id;
    }

    /**
     * @return array<int, string>
     */
    private static function parentDeviceOptions(Get $get, ?Device $record): array
    {
        $organizationId = $get('organization_id');

        if (! is_numeric($organizationId) && $record?->organization_id === null) {
            return [];
        }

        $resolvedOrganizationId = is_numeric($organizationId)
            ? (int) $organizationId
            : $record?->organization_id;

        if (! is_int($resolvedOrganizationId)) {
            return [];
        }

        $query = Device::query()
            ->where('organization_id', $resolvedOrganizationId)
            ->whereNull('parent_device_id')
            ->orderBy('name');

        if ($record !== null) {
            $query->whereKeyNot($record->getKey());
        }

        return $query
            ->get(['id', 'name', 'external_id'])
            ->mapWithKeys(function (Device $device): array {
                $externalId = is_string($device->external_id) && trim($device->external_id) !== ''
                    ? " · {$device->external_id}"
                    : '';

                return [
                    (int) $device->id => "{$device->name}{$externalId}",
                ];
            })
            ->all();
    }
}
