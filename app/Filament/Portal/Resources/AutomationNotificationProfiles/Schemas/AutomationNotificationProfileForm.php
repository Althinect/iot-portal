<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\AutomationNotificationProfiles\Schemas;

use App\Domain\Automation\Models\AutomationNotificationProfile;
use App\Domain\Shared\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

class AutomationNotificationProfileForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Hidden::make('organization_id')
                    ->default(fn (): mixed => Filament::getTenant()?->getKey()),
                Section::make('Profile')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->unique(
                                table: AutomationNotificationProfile::class,
                                column: 'name',
                                ignoreRecord: true,
                                modifyRuleUsing: function (Unique $rule): Unique {
                                    $tenantId = Filament::getTenant()?->getKey();

                                    return is_numeric($tenantId)
                                        ? $rule->where('organization_id', (int) $tenantId)
                                        : $rule;
                                },
                            ),
                        Select::make('channel')
                            ->options([
                                'sms' => 'SMS',
                                'email' => 'Email',
                            ])
                            ->required()
                            ->default('email')
                            ->live()
                            ->afterStateUpdated(fn (Set $set): mixed => $set('recipient_user_ids', [])),
                        Toggle::make('enabled')
                            ->default(true),
                        Select::make('recipient_user_ids')
                            ->label('Recipients')
                            ->multiple()
                            ->options(fn (Get $get): array => self::recipientOptions($get('channel')))
                            ->helperText('Only members of the active organization can be selected.')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Template')
                    ->schema([
                        TextInput::make('subject')
                            ->required(fn (Get $get): bool => $get('channel') === 'email'),
                        TextInput::make('mask')
                            ->visible(fn (Get $get): bool => $get('channel') === 'sms'),
                        TextInput::make('campaign_name')
                            ->label('Campaign name')
                            ->visible(fn (Get $get): bool => $get('channel') === 'sms'),
                        Textarea::make('body')
                            ->rows(8)
                            ->required()
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    /** @return array<int|string, string> */
    private static function recipientOptions(mixed $channel): array
    {
        $tenantId = Filament::getTenant()?->getKey();

        return User::query()
            ->when(
                is_numeric($tenantId),
                fn (Builder $query): Builder => $query->whereHas(
                    'organizations',
                    fn (Builder $organizationQuery): Builder => $organizationQuery->whereKey((int) $tenantId),
                ),
                fn (Builder $query): Builder => $query->whereRaw('1 = 0'),
            )
            ->when(
                $channel === 'sms',
                fn (Builder $query): Builder => $query->whereNotNull('phone_number'),
                fn (Builder $query): Builder => $query->whereNotNull('email'),
            )
            ->orderBy('name')
            ->get()
            ->mapWithKeys(function (User $user) use ($channel): array {
                $route = $channel === 'sms' ? $user->phone_number : $user->email;

                return [(string) $user->id => "{$user->name} ({$route})"];
            })
            ->all();
    }
}
