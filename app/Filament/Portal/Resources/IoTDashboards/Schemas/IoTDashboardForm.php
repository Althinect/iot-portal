<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\IoTDashboards\Schemas;

use App\Domain\IoTDashboard\Enums\DashboardHistoryPreset;
use App\Domain\IoTDashboard\Models\IoTDashboard;
use App\Domain\Shared\Models\Entity;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

class IoTDashboardForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dashboard details')
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                            $currentSlug = $get('slug');

                            if (is_string($currentSlug) && trim($currentSlug) !== '') {
                                return;
                            }

                            if (is_string($state) && trim($state) !== '') {
                                $set('slug', Str::slug($state));
                            }
                        }),
                    TextInput::make('slug')
                        ->required()
                        ->maxLength(180)
                        ->helperText('Unique within your organization.')
                        ->unique(
                            table: IoTDashboard::class,
                            column: 'slug',
                            ignoreRecord: true,
                            modifyRuleUsing: function (Unique $rule): Unique {
                                $tenantId = Filament::getTenant()?->getKey();

                                return is_numeric($tenantId)
                                    ? $rule->where('organization_id', (int) $tenantId)
                                    : $rule;
                            },
                        ),
                    Select::make('entity_id')
                        ->label('Primary site')
                        ->options(function (): array {
                            $tenantId = Filament::getTenant()?->getKey();

                            return Entity::query()
                                ->when(
                                    is_numeric($tenantId),
                                    fn (Builder $query): Builder => $query->where('organization_id', (int) $tenantId),
                                    fn (Builder $query): Builder => $query->whereRaw('1 = 0'),
                                )
                                ->orderBy('label')
                                ->pluck('label', 'id')
                                ->all();
                        })
                        ->searchable()
                        ->preload(),
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
