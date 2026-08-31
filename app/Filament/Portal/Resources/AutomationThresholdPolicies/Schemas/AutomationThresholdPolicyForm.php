<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\AutomationThresholdPolicies\Schemas;

use App\Domain\Automation\Models\AutomationNotificationProfile;
use App\Domain\Automation\Services\GuidedConditionService;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Enums\ChannelDirection;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class AutomationThresholdPolicyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Hidden::make('organization_id')
                ->default(fn (): mixed => Filament::getTenant()?->getKey()),
            Hidden::make('condition_mode')->default('guided'),
            Hidden::make('guided_condition.left')->default('trigger.value'),
            Section::make('Threshold')
                ->description('Choose a telemetry parameter and the condition that should open an alert.')
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255),
                    Select::make('device_id')
                        ->label('Device')
                        ->options(fn (): array => self::deviceOptions())
                        ->required()
                        ->searchable()
                        ->preload()
                        ->live()
                        ->afterStateUpdated(fn (Set $set): mixed => $set('parameter_key', null)),
                    Select::make('parameter_key')
                        ->label('Telemetry parameter')
                        ->options(fn (Get $get): array => self::parameterOptions($get('device_id')))
                        ->required()
                        ->searchable()
                        ->preload(),
                    Select::make('guided_condition.operator')
                        ->label('Condition')
                        ->options(fn (): array => collect(app(GuidedConditionService::class)->operatorOptions())
                            ->pluck('label', 'value')
                            ->all())
                        ->default('>')
                        ->required()
                        ->live(),
                    TextInput::make('guided_condition.right')
                        ->label('Threshold value')
                        ->numeric()
                        ->required(),
                    TextInput::make('guided_condition.right_secondary')
                        ->label('Second value')
                        ->numeric()
                        ->required(fn (Get $get): bool => in_array($get('guided_condition.operator'), ['between', 'outside_between'], true))
                        ->visible(fn (Get $get): bool => in_array($get('guided_condition.operator'), ['between', 'outside_between'], true)),
                    Select::make('notification_profile_id')
                        ->label('Notification profile')
                        ->options(fn (): array => self::notificationProfileOptions())
                        ->searchable()
                        ->preload(),
                    Toggle::make('is_active')
                        ->default(true),
                ])
                ->columns(2),
            Section::make('Alert timing')
                ->schema([
                    TextInput::make('cooldown_value')
                        ->integer()
                        ->minValue(1)
                        ->default(1)
                        ->required(),
                    Select::make('cooldown_unit')
                        ->options([
                            'minute' => 'Minute(s)',
                            'hour' => 'Hour(s)',
                            'day' => 'Day(s)',
                        ])
                        ->default('day')
                        ->required(),
                    TextInput::make('sort_order')
                        ->integer()
                        ->minValue(0)
                        ->default(0)
                        ->required(),
                ])
                ->columns(3),
        ]);
    }

    /** @return array<int|string, string> */
    private static function deviceOptions(): array
    {
        $tenantId = Filament::getTenant()?->getKey();

        return Device::query()
            ->when(
                is_numeric($tenantId),
                fn (Builder $query): Builder => $query->where('organization_id', (int) $tenantId),
                fn (Builder $query): Builder => $query->whereRaw('1 = 0'),
            )
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /** @return array<int|string, string> */
    private static function parameterOptions(mixed $deviceId): array
    {
        $tenantId = Filament::getTenant()?->getKey();

        if (! is_numeric($tenantId) || ! is_numeric($deviceId)) {
            return [];
        }

        $profileVersionId = Device::query()
            ->where('organization_id', (int) $tenantId)
            ->whereKey((int) $deviceId)
            ->value('device_profile_version_id');

        if (! is_numeric($profileVersionId)) {
            return [];
        }

        $channelIds = DeviceChannel::query()
            ->where('device_profile_version_id', (int) $profileVersionId)
            ->where('direction', ChannelDirection::Publish->value)
            ->pluck('id');

        return ProfileParameterDefinition::query()
            ->with('channel:id,label')
            ->whereIn('device_channel_id', $channelIds)
            ->where('is_active', true)
            ->orderBy('label')
            ->get()
            ->mapWithKeys(fn (ProfileParameterDefinition $parameter): array => [
                (string) $parameter->id => sprintf(
                    '%s · %s (%s)',
                    $parameter->channel?->label ?? 'Telemetry',
                    $parameter->label,
                    $parameter->key,
                ),
            ])
            ->all();
    }

    /** @return array<int|string, string> */
    private static function notificationProfileOptions(): array
    {
        $tenantId = Filament::getTenant()?->getKey();

        return AutomationNotificationProfile::query()
            ->when(
                is_numeric($tenantId),
                fn (Builder $query): Builder => $query->where('organization_id', (int) $tenantId),
                fn (Builder $query): Builder => $query->whereRaw('1 = 0'),
            )
            ->where('enabled', true)
            ->orderBy('name')
            ->get(['id', 'name', 'channel'])
            ->mapWithKeys(fn (AutomationNotificationProfile $profile): array => [
                (string) $profile->id => "{$profile->name} (".strtoupper($profile->channel).')',
            ])
            ->all();
    }
}
