<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DeviceProfiles\Schemas;

use App\Domain\DeviceProfile\Models\DeviceProfile;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Filament\Admin\Resources\DeviceProfileVersions\DeviceProfileVersionResource;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DeviceProfileInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Profile')
                    ->schema([
                        TextEntry::make('name')
                            ->weight('bold'),
                        TextEntry::make('key')
                            ->copyable(),
                        TextEntry::make('organization.name')
                            ->label('Scope')
                            ->state(fn (DeviceProfile $record): string => $record->organization?->name ?? 'Global')
                            ->badge(),
                        TextEntry::make('activeVersion.version')
                            ->label('Active version')
                            ->state(fn (DeviceProfile $record): string => $record->activeVersion instanceof DeviceProfileVersion ? "Version {$record->activeVersion->version}" : 'None')
                            ->url(fn (DeviceProfile $record): ?string => $record->activeVersion instanceof DeviceProfileVersion
                                ? DeviceProfileVersionResource::getUrl('edit', ['record' => $record->activeVersion])
                                : null),
                    ])
                    ->columns(2),
                Section::make('Counts')
                    ->schema([
                        TextEntry::make('versions_count')
                            ->label('Versions')
                            ->state(fn (DeviceProfile $record): int => $record->versions()->count()),
                        TextEntry::make('channels_count')
                            ->label('Channels')
                            ->state(fn (DeviceProfile $record): int => $record->channels()->count()),
                        TextEntry::make('devices_count')
                            ->label('Linked devices')
                            ->state(fn (DeviceProfile $record): int => $record->devices()->count()),
                    ])
                    ->columns(3),
                Section::make('Tags')
                    ->schema([
                        KeyValueEntry::make('tags')
                            ->state(fn (DeviceProfile $record): array => is_array($record->tags) ? $record->tags : [])
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
