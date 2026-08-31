<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\AutomationThresholdPolicies\Schemas;

use App\Domain\Automation\Models\AutomationThresholdPolicy;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AutomationThresholdPolicyInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Threshold')
                ->schema([
                    TextEntry::make('name'),
                    TextEntry::make('device.name')->label('Device'),
                    TextEntry::make('parameter_key')->label('Parameter'),
                    TextEntry::make('condition')
                        ->state(fn (AutomationThresholdPolicy $record): string => $record->conditionLabel()),
                    IconEntry::make('is_active')->boolean(),
                    TextEntry::make('notificationProfile.name')->label('Notification profile'),
                    TextEntry::make('cooldown')
                        ->state(fn (AutomationThresholdPolicy $record): string => "{$record->cooldown_value} {$record->cooldown_unit}"),
                ])
                ->columns(2),
        ]);
    }
}
