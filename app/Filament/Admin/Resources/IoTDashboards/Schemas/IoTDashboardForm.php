<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\IoTDashboards\Schemas;

use App\Domain\IoTDashboard\Enums\DashboardHistoryPreset;
use App\Domain\IoTDashboard\Models\IoTDashboard;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

class IoTDashboardForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Dashboard Details')
                    ->schema([
                        Select::make('organization_id')
                            ->relationship('organization', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),

                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                                $currentSlugValue = $get('slug');
                                $currentSlug = is_string($currentSlugValue)
                                    ? trim($currentSlugValue)
                                    : '';

                                if ($currentSlug !== '') {
                                    return;
                                }

                                if (! is_string($state) || trim($state) === '') {
                                    return;
                                }

                                $set('slug', Str::slug($state));
                            }),

                        TextInput::make('slug')
                            ->required()
                            ->maxLength(180)
                            ->helperText('Unique within the selected organization.')
                            ->unique(
                                table: IoTDashboard::class,
                                column: 'slug',
                                ignoreRecord: true,
                                modifyRuleUsing: function (Unique $rule, Get $get): Unique {
                                    $organizationId = $get('organization_id');

                                    if (! is_numeric($organizationId)) {
                                        return $rule;
                                    }

                                    return $rule->where('organization_id', (int) $organizationId);
                                },
                            ),

                        TextInput::make('refresh_interval_seconds')
                            ->label('Refresh interval (seconds)')
                            ->integer()
                            ->minValue(2)
                            ->maxValue(300)
                            ->default(10)
                            ->required(),

                        Select::make('default_history_preset')
                            ->label('Default history range')
                            ->options(DashboardHistoryPreset::class)
                            ->default(DashboardHistoryPreset::Last6Hours->value)
                            ->required(),

                        Toggle::make('is_active')
                            ->default(true)
                            ->required(),

                        Textarea::make('description')
                            ->maxLength(500)
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}
