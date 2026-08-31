<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\DeviceProfiles\Schemas;

use App\Domain\DeviceProfile\Models\DeviceProfile;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DeviceProfileInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Contract identity')
                ->schema([
                    TextEntry::make('name'),
                    TextEntry::make('key'),
                    TextEntry::make('scope')
                        ->state(fn (DeviceProfile $record): string => $record->isGlobal() ? 'Platform library' : 'Private to your organization')
                        ->badge()
                        ->color(fn (DeviceProfile $record): string => $record->isGlobal() ? 'info' : 'success'),
                    TextEntry::make('versions_count')
                        ->state(fn (DeviceProfile $record): int => $record->versions()->count())
                        ->label('Contract versions'),
                    KeyValueEntry::make('tags')
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }
}
