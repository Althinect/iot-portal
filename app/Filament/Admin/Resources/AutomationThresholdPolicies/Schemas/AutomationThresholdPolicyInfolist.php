<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AutomationThresholdPolicies\Schemas;

use App\Domain\Automation\Models\AutomationThresholdPolicy;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AutomationThresholdPolicyInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Policy')
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('organization.name')->label('Organization'),
                        TextEntry::make('device.name')->label('Device'),
                        TextEntry::make('parameter_key')
                            ->label('Parameter')
                            ->state(
                                fn (AutomationThresholdPolicy $record): string => $record->profileParameterDefinition()?->label
                                    ?? (string) $record->parameter_key,
                            ),
                        TextEntry::make('range')
                            ->state(fn (AutomationThresholdPolicy $record): string => $record->rangeLabel()),
                        IconEntry::make('is_active')
                            ->boolean()
                            ->label('Active'),
                        TextEntry::make('notificationProfile.name')->label('Notification profile'),
                        TextEntry::make('legacy_alert_rule_id')->label('Legacy rule'),
                    ])
                    ->columns(2),
                Section::make('Timing')
                    ->schema([
                        TextEntry::make('cooldown_value'),
                        TextEntry::make('cooldown_unit'),
                        TextEntry::make('sort_order'),
                        TextEntry::make('managedWorkflow.name')->label('Managed workflow'),
                    ])
                    ->columns(2),
            ]);
    }
}
