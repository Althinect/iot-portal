<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AutomationThresholdPolicies;

use App\Domain\Alerts\Models\ThresholdPolicy;
use App\Domain\Automation\Models\AutomationNotificationProfile;
use App\Domain\Automation\Models\AutomationThresholdPolicy;
use App\Domain\Automation\Services\GuidedConditionService;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Enums\ChannelDirection;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;

class AutomationThresholdPolicyResource extends Resource
{
    protected static ?string $model = AutomationThresholdPolicy::class;

    protected static ?string $slug = 'threshold-policies';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?int $navigationSort = 6;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): ?string
    {
        return __('Alerts');
    }

    public static function getNavigationLabel(): string
    {
        return __('Threshold Policies');
    }

    public static function form(Schema $schema): Schema
    {
        return Schemas\AutomationThresholdPolicyForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return Schemas\AutomationThresholdPolicyInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return Tables\AutomationThresholdPoliciesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAutomationThresholdPolicies::route('/'),
            'create' => Pages\CreateAutomationThresholdPolicy::route('/create'),
            'view' => Pages\ViewAutomationThresholdPolicy::route('/{record}'),
            'edit' => Pages\EditAutomationThresholdPolicy::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function prepareThresholdPolicyFormData(array $data, ?int $ignoreRecordId = null): array
    {
        $organizationId = is_numeric($data['organization_id'] ?? null)
            ? (int) $data['organization_id']
            : null;
        $deviceId = is_numeric($data['device_id'] ?? null)
            ? (int) $data['device_id']
            : null;
        $parameterDefinitionId = is_numeric($data['parameter_key'] ?? null)
            ? (int) $data['parameter_key']
            : null;
        $notificationProfileId = is_numeric($data['notification_profile_id'] ?? null)
            ? (int) $data['notification_profile_id']
            : null;
        $isActive = (bool) ($data['is_active'] ?? false);
        $conditionMode = ($data['condition_mode'] ?? 'guided') === 'json_logic' ? 'json_logic' : 'guided';
        $conditionService = app(GuidedConditionService::class);

        if ($organizationId === null || $deviceId === null || $parameterDefinitionId === null) {
            return $data;
        }

        $device = Device::query()
            ->whereKey($deviceId)
            ->where('organization_id', $organizationId)
            ->first(['id', 'device_profile_version_id']);

        if (! $device instanceof Device) {
            throw ValidationException::withMessages([
                'device_id' => 'The selected device does not belong to the chosen organization.',
            ]);
        }

        $publishTopicIds = DeviceChannel::query()
            ->where('device_profile_version_id', (int) $device->device_profile_version_id)
            ->where('direction', ChannelDirection::Publish->value)
            ->pluck('id')
            ->all();

        $parameter = ProfileParameterDefinition::query()
            ->whereKey($parameterDefinitionId)
            ->where('is_active', true)
            ->whereIn('device_channel_id', $publishTopicIds)
            ->first(['id', 'key', 'device_channel_id']);

        if (! $parameter instanceof ProfileParameterDefinition) {
            throw ValidationException::withMessages([
                'parameter_key' => 'The selected parameter must belong to the device telemetry schema.',
            ]);
        }

        $data['device_channel_id'] = $parameter->device_channel_id;
        $data['parameter_key'] = $parameter->key;

        if ($notificationProfileId !== null) {
            $profileExists = AutomationNotificationProfile::query()
                ->whereKey($notificationProfileId)
                ->where('organization_id', $organizationId)
                ->exists();

            if (! $profileExists) {
                throw ValidationException::withMessages([
                    'notification_profile_id' => 'The selected notification profile must belong to the same organization.',
                ]);
            }
        }

        try {
            if ($conditionMode === 'guided') {
                $guidedConditionInput = [
                    'left' => 'trigger.value',
                    'operator' => Arr::get($data, 'guided_condition.operator', 'outside_between'),
                    'right' => Arr::get($data, 'guided_condition.right'),
                    'right_secondary' => Arr::get($data, 'guided_condition.right_secondary'),
                ];
                $guidedCondition = $conditionService->normalize($guidedConditionInput);
                $conditionJsonLogic = $conditionService->compile($guidedCondition);

                $data['condition_mode'] = 'guided';
                $data['guided_condition'] = $guidedCondition;
                $data['condition_json_logic'] = $conditionJsonLogic;
            } else {
                $guidedCondition = $data['guided_condition'] ?? null;
                $conditionJsonLogic = self::decodeConditionJsonLogic(
                    $data['condition_json_logic_text'] ?? null,
                );

                $data['condition_mode'] = 'json_logic';
                if (is_array($guidedCondition)) {
                    /** @var array<string, mixed> $guidedCondition */
                    $data['guided_condition'] = $conditionService->normalize($guidedCondition);
                } else {
                    $data['guided_condition'] = null;
                }
                $data['condition_json_logic'] = $conditionJsonLogic;
            }
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'guided_condition.right' => $exception->getMessage(),
            ]);
        } catch (JsonException) {
            throw ValidationException::withMessages([
                'condition_json_logic_text' => 'JSON logic must be valid JSON.',
            ]);
        }

        /** @var mixed $conditionJsonLogic */
        $conditionJsonLogic = $data['condition_json_logic'];

        if (! is_array($conditionJsonLogic) || $conditionJsonLogic === [] || ! Arr::isAssoc($conditionJsonLogic) || count($conditionJsonLogic) !== 1) {
            throw ValidationException::withMessages([
                'condition_json_logic_text' => 'JSON logic must be an object with a single root operator.',
            ]);
        }

        if (! $isActive) {
            unset($data['condition_json_logic_text']);

            return $data;
        }

        $duplicateExists = AutomationThresholdPolicy::query()
            ->where('organization_id', $organizationId)
            ->where('device_id', $deviceId)
            ->where('device_channel_id', $parameter->device_channel_id)
            ->where('parameter_key', $parameter->key)
            ->where('is_active', true)
            ->when($ignoreRecordId !== null, fn (Builder $query) => $query->whereKeyNot($ignoreRecordId))
            ->exists();

        if ($duplicateExists) {
            throw ValidationException::withMessages([
                'parameter_key' => 'Only one active threshold policy is allowed per device and parameter.',
            ]);
        }

        unset($data['condition_json_logic_text']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function prepareThresholdPolicyFormDataBeforeFill(
        array $data,
        ThresholdPolicy $policy,
    ): array {
        $parameter = $policy->profileParameterDefinition();

        if ($parameter instanceof ProfileParameterDefinition) {
            $data['parameter_key'] = (string) $parameter->id;
        }

        $data['condition_json_logic_text'] = json_encode(
            is_array($data['condition_json_logic'] ?? null) ? $data['condition_json_logic'] : [],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ) ?: '{}';

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeConditionJsonLogic(mixed $value): array
    {
        if (is_array($value)) {
            /** @var array<string, mixed> $value */
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }
}
