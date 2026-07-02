<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DeviceProfileVersions\Schemas;

use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DeviceProfileVersionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Contract')
                    ->schema([
                        TextEntry::make('profile.name')
                            ->label('Profile'),
                        TextEntry::make('version')
                            ->formatStateUsing(fn (mixed $state): string => is_scalar($state) ? "Version {$state}" : '—'),
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                DeviceProfileVersion::STATUS_ACTIVE => 'success',
                                DeviceProfileVersion::STATUS_SUPERSEDED => 'gray',
                                default => 'warning',
                            }),
                        TextEntry::make('protocol')
                            ->badge(),
                        TextEntry::make('notes')
                            ->label('Change summary')
                            ->columnSpanFull()
                            ->placeholder('None'),
                    ])
                    ->columns(2),
                Section::make('Counts')
                    ->schema([
                        TextEntry::make('channels_count')
                            ->label('Channels')
                            ->state(fn (DeviceProfileVersion $record): int => $record->channels()->count()),
                        TextEntry::make('derived_parameters_count')
                            ->label('Derived parameters')
                            ->state(fn (DeviceProfileVersion $record): int => $record->derivedParameters()->count()),
                        TextEntry::make('devices_count')
                            ->label('Linked devices')
                            ->state(fn (DeviceProfileVersion $record): int => $record->devices()->count()),
                    ])
                    ->columns(3),
                Section::make('Protocol config')
                    ->schema([
                        KeyValueEntry::make('protocol_config')
                            ->state(fn (DeviceProfileVersion $record): array => $record->protocol_config?->toArray() ?? [])
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
