<?php

declare(strict_types=1);

namespace App\Filament\Actions\DeviceManagement;

use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
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
            ->excludeAttributes(['uuid', 'connection_state', 'last_seen_at', 'child_devices_count', 'virtual_device_links_count', 'created_at', 'updated_at', 'deleted_at'])
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
                        $set('device_profile_version_id', null);
                    }),

                Select::make('device_profile_version_id')
                    ->label('Profile Version')
                    ->options(fn (Get $get): array => self::profileVersionOptions($get))
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
    private static function profileVersionOptions(Get $get): array
    {
        $organizationId = $get('organization_id');

        return DeviceProfileVersion::query()
            ->with('profile')
            ->when(is_numeric($organizationId), function ($query) use ($organizationId): void {
                $query->whereHas('profile', function ($profileQuery) use ($organizationId): void {
                    $profileQuery
                        ->whereNull('organization_id')
                        ->orWhere('organization_id', (int) $organizationId);
                });
            })
            ->when(! is_numeric($organizationId), function ($query): void {
                $query->whereHas('profile', fn ($profileQuery) => $profileQuery->whereNull('organization_id'));
            })
            ->where('status', 'active')
            ->orderByDesc('version')
            ->get(['id', 'device_profile_id', 'version'])
            ->mapWithKeys(function (DeviceProfileVersion $profileVersion): array {
                $profileName = data_get($profileVersion, 'profile.name', 'Profile');
                $profileName = is_string($profileName) && trim($profileName) !== '' ? $profileName : 'Profile';
                $version = (string) $profileVersion->version;

                return [
                    (int) $profileVersion->id => "{$profileName} · v{$version}",
                ];
            })
            ->all();
    }
}
