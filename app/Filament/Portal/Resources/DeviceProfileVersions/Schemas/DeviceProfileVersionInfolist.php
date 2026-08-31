<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\DeviceProfileVersions\Schemas;

use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DeviceProfileVersionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Contract version')
                ->schema([
                    TextEntry::make('profile.name')
                        ->label('Profile'),
                    TextEntry::make('version')
                        ->formatStateUsing(fn (mixed $state): string => is_numeric($state) ? "v{$state}" : 'Draft'),
                    TextEntry::make('status')
                        ->badge()
                        ->color(fn (string $state): string => match ($state) {
                            DeviceProfileVersion::STATUS_ACTIVE => 'success',
                            DeviceProfileVersion::STATUS_SUPERSEDED => 'gray',
                            default => 'warning',
                        }),
                    TextEntry::make('protocol')
                        ->badge(),
                    TextEntry::make('channels_count')
                        ->state(fn (DeviceProfileVersion $record): int => $record->channels()->count())
                        ->label('Channels'),
                    TextEntry::make('notes')
                        ->label('Contract notes')
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }
}
