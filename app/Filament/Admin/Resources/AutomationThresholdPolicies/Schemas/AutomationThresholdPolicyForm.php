<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AutomationThresholdPolicies\Schemas;

use App\Domain\Automation\Models\AutomationNotificationProfile;
use App\Domain\Automation\Services\GuidedConditionService;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Enums\ChannelDirection;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use App\Support\DeviceSelectOptions;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class AutomationThresholdPolicyForm
{
    public static function configure(Schema $schema): Schema
    {
        $conditionService = app(GuidedConditionService::class);

        return $schema
            ->columns(2)
            ->components([
                Section::make('Policy')
                    ->schema([
                        Select::make('organization_id')
                            ->relationship('organization', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $set('device_id', null);
                                $set('parameter_key', null);
                                $set('notification_profile_id', null);
                            }),

                        Select::make('device_id')
                            ->label('Device')
                            ->options(fn (Get $get): array => self::deviceOptions($get))
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set, Get $get): void {
                                $set('parameter_key', null);

                                $deviceLabel = self::resolveOptionLabel(self::deviceOptions($get), $get('device_id'));
                                $parameterLabel = self::resolveOptionLabel(self::parameterOptions($get), $get('parameter_key'));

                                if (is_string($deviceLabel) && is_string($parameterLabel)) {
                                    $set('name', Str::before($deviceLabel, ' (').' · '.Str::before($parameterLabel, ' ('));
                                }
                            }),

                        Select::make('parameter_key')
                            ->label('Telemetry parameter')
                            ->options(fn (Get $get): array => self::parameterOptions($get))
                            ->searchable()
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set, Get $get): void {
                                $deviceLabel = self::resolveOptionLabel(self::deviceOptions($get), $get('device_id'));
                                $parameterLabel = self::resolveOptionLabel(self::parameterOptions($get), $get('parameter_key'));

                                if (is_string($deviceLabel) && is_string($parameterLabel)) {
                                    $set('name', Str::before($deviceLabel, ' (').' · '.Str::before($parameterLabel, ' ('));
                                }
                            }),

                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),

                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),

                        Select::make('notification_profile_id')
                            ->label('Notification profile')
                            ->options(fn (Get $get): array => self::notificationProfileOptions($get))
                            ->searchable()
                            ->preload(),
                    ])
                    ->columns(2),

                Section::make('Thresholds')
                    ->schema([
                        Select::make('condition_mode')
                            ->label('Rule mode')
                            ->options([
                                'guided' => 'Guided',
                                'json_logic' => 'Advanced JSON logic',
                            ])
                            ->default('guided')
                            ->required()
                            ->live(),

                        Hidden::make('guided_condition.left')
                            ->default('trigger.value'),

                        Select::make('guided_condition.operator')
                            ->label('Operator')
                            ->options(collect($conditionService->operatorOptions())->mapWithKeys(
                                fn (array $operator): array => [$operator['value'] => $operator['label']],
                            )->all())
                            ->default('outside_between')
                            ->required()
                            ->visible(fn (Get $get): bool => $get('condition_mode') !== 'json_logic')
                            ->live(),

                        TextInput::make('guided_condition.right')
                            ->label(fn (Get $get): string => in_array($get('guided_condition.operator'), ['between', 'outside_between'], true)
                                ? 'Lower bound'
                                : 'Threshold')
                            ->numeric()
                            ->required(fn (Get $get): bool => $get('condition_mode') !== 'json_logic')
                            ->visible(fn (Get $get): bool => $get('condition_mode') !== 'json_logic'),

                        TextInput::make('guided_condition.right_secondary')
                            ->label('Upper bound')
                            ->numeric()
                            ->required(fn (Get $get): bool => $get('condition_mode') !== 'json_logic'
                                && in_array($get('guided_condition.operator'), ['between', 'outside_between'], true))
                            ->visible(fn (Get $get): bool => $get('condition_mode') !== 'json_logic'
                                && in_array($get('guided_condition.operator'), ['between', 'outside_between'], true)),

                        CodeEditor::make('condition_json_logic_text')
                            ->language(Language::Json)
                            ->label('JSON logic')
                            ->default("{\n  \">\": [\n    {\"var\": \"trigger.value\"},\n    240\n  ]\n}")
                            ->visible(fn (Get $get): bool => $get('condition_mode') === 'json_logic')
                            ->required(fn (Get $get): bool => $get('condition_mode') === 'json_logic')
                            ->columnSpanFull(),

                        TextInput::make('cooldown_value')
                            ->label('Cooldown value')
                            ->integer()
                            ->default(1)
                            ->minValue(1)
                            ->required(),

                        Select::make('cooldown_unit')
                            ->label('Cooldown unit')
                            ->options([
                                'minute' => 'Minute',
                                'hour' => 'Hour',
                                'day' => 'Day',
                            ])
                            ->default('day')
                            ->required(),

                        TextInput::make('sort_order')
                            ->integer()
                            ->default(0)
                            ->minValue(0),

                        Placeholder::make('range_preview')
                            ->label('Rule preview')
                            ->content(function (Get $get): string {
                                if ($get('condition_mode') === 'json_logic') {
                                    return 'Custom JSON logic rule.';
                                }

                                $guidedCondition = [
                                    'left' => 'trigger.value',
                                    'operator' => $get('guided_condition.operator'),
                                    'right' => $get('guided_condition.right'),
                                    'right_secondary' => $get('guided_condition.right_secondary'),
                                ];

                                try {
                                    return app(GuidedConditionService::class)->label($guidedCondition);
                                } catch (\Throwable) {
                                    return 'Complete the guided rule to preview it.';
                                }
                            }),

                        TextInput::make('legacy_alert_rule_id')
                            ->label('Legacy alert rule ID')
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn ($record): bool => $record !== null),
                    ])
                    ->columns(2),
            ]);
    }

    /**
     * @return array<int|string, string>|array<string, array<int|string, string>>
     */
    private static function deviceOptions(Get $get): array
    {
        $organizationId = $get('organization_id');

        if (! is_numeric($organizationId)) {
            return [];
        }

        return DeviceSelectOptions::groupedByType(
            Device::query()->where('organization_id', (int) $organizationId),
        );
    }

    /**
     * @return array<int|string, string>
     */
    private static function parameterOptions(Get $get): array
    {
        $organizationId = $get('organization_id');
        $deviceId = $get('device_id');

        if (! is_numeric($organizationId) || ! is_numeric($deviceId)) {
            return [];
        }

        $device = Device::query()
            ->whereKey((int) $deviceId)
            ->where('organization_id', (int) $organizationId)
            ->first(['id', 'device_profile_version_id']);

        if (! $device instanceof Device) {
            return [];
        }

        $publishTopicIds = DeviceChannel::query()
            ->where('device_profile_version_id', (int) $device->device_profile_version_id)
            ->where('direction', ChannelDirection::Publish->value)
            ->pluck('id')
            ->all();

        return ProfileParameterDefinition::query()
            ->whereIn('device_channel_id', $publishTopicIds)
            ->where('is_active', true)
            ->orderBy('label')
            ->get(['id', 'label', 'key', 'device_channel_id'])
            ->mapWithKeys(function (ProfileParameterDefinition $parameter): array {
                $topicLabel = DeviceChannel::query()
                    ->whereKey($parameter->device_channel_id)
                    ->value('label');
                $prefix = is_string($topicLabel) && trim($topicLabel) !== '' ? "{$topicLabel} · " : '';

                return [
                    (string) $parameter->id => "{$prefix}{$parameter->label} ({$parameter->key})",
                ];
            })
            ->all();
    }

    /**
     * @return array<int|string, string>
     */
    private static function notificationProfileOptions(Get $get): array
    {
        $organizationId = $get('organization_id');

        if (! is_numeric($organizationId)) {
            return [];
        }

        return AutomationNotificationProfile::query()
            ->where('organization_id', (int) $organizationId)
            ->orderBy('name')
            ->get(['id', 'name', 'channel'])
            ->mapWithKeys(fn (AutomationNotificationProfile $profile): array => [
                (string) $profile->id => "{$profile->name} (".strtoupper($profile->channel).')',
            ])
            ->all();
    }

    /**
     * @param  array<int|string, string>|array<string, array<int|string, string>>  $options
     */
    private static function resolveOptionLabel(array $options, mixed $selectedValue): ?string
    {
        return DeviceSelectOptions::findLabel($options, $selectedValue);
    }
}
