<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\AutomationNotificationProfiles\Schemas;

use App\Domain\Automation\Models\AutomationNotificationProfile;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AutomationNotificationProfileInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Profile')
                ->schema([
                    TextEntry::make('name'),
                    TextEntry::make('channel')->badge(),
                    IconEntry::make('enabled')->boolean(),
                    TextEntry::make('users')
                        ->label('Recipients')
                        ->state(fn (AutomationNotificationProfile $record): string => implode(', ', $record->recipientLabels()))
                        ->columnSpanFull(),
                ])
                ->columns(2),
            Section::make('Template')
                ->schema([
                    TextEntry::make('subject'),
                    TextEntry::make('mask'),
                    TextEntry::make('campaign_name')->label('Campaign name'),
                    TextEntry::make('body')->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }
}
