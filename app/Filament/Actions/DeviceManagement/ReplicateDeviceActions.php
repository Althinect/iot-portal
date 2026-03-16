<?php

declare(strict_types=1);

namespace App\Filament\Actions\DeviceManagement;

use App\Domain\DeviceManagement\Models\DeviceType;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Domain\Shared\Models\Organization;
use Filament\Actions\ReplicateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

final class ReplicateDeviceActions
{
    public static function make(): ReplicateAction
    {
        return ReplicateAction::make()
            ->icon(Heroicon::OutlinedSquare3Stack3d)
            ->excludeAttributes(['uuid', 'connection_state', 'last_seen_at', 'created_at', 'updated_at', 'deleted_at', 'child_devices_count'])
            ->mutateRecordDataUsing(function (array $data): array {
                $name = isset($data['name']) && is_string($data['name']) ? $data['name'] : 'Device';

                $data['name'] = Str::limit("{$name} Copy", 255, '');
                $data['external_id'] = null;
                $data['is_active'] = false;
                $data['connection_state'] = null;
                $data['last_seen_at'] = null;

                return $data;
            })
            ->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),

                TextInput::make('external_id')
                    ->label('External ID')
                    ->maxLength(255)
                    ->helperText('Optional unique hardware identifier for the replicated device.'),

                Select::make('organization_id')
                    ->label('Organization')
                    ->options(fn (): array => Organization::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->required()
                    ->searchable()
                    ->preload()
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
                    ->preload()
                    ->live()
                    ->afterStateUpdated(function (Set $set, Get $get): void {
                        $set('device_schema_version_id', self::defaultActiveSchemaVersionId($get('device_type_id')));
                    }),

                Select::make('device_schema_version_id')
                    ->label('Schema Version')
                    ->options(fn (Get $get): array => self::schemaVersionOptions($get))
                    ->required()
                    ->searchable()
                    ->preload(),

                Toggle::make('is_active')
                    ->label('Active'),
            ]);
    }

    /**
     * @return array<int, string>
     */
    private static function deviceTypeOptions(Get $get): array
    {
        $organizationId = $get('organization_id');

        $query = DeviceType::query()->orderBy('name');

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
}
