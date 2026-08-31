<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\DeviceProfiles\Schemas;

use App\Domain\DeviceProfile\Models\DeviceProfile;
use Filament\Facades\Filament;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class DeviceProfileForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Contract identity')
                ->description('Profiles describe the channels and parameters understood by your devices. Platform transport and security settings remain managed by the platform team.')
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('key')
                        ->required()
                        ->maxLength(100)
                        ->regex('/^[a-z0-9_:-]+$/')
                        ->helperText('A stable lowercase identifier, for example energy_meter.')
                        ->unique(
                            table: DeviceProfile::class,
                            column: 'key',
                            ignoreRecord: true,
                            modifyRuleUsing: function (Unique $rule): Unique {
                                $tenantId = Filament::getTenant()?->getKey();

                                return is_numeric($tenantId)
                                    ? $rule->where('organization_id', (int) $tenantId)
                                    : $rule;
                            },
                        ),
                    KeyValue::make('tags')
                        ->label('Contract tags')
                        ->keyLabel('Tag')
                        ->valueLabel('Value')
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }
}
