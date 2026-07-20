<?php

declare(strict_types=1);

namespace App\Domain\Alerts\Models;

use App\Domain\Alerts\Actions\NormalizeThresholdPolicyAlerts;
use App\Domain\Automation\Models\AutomationWorkflow;
use App\Domain\Automation\Services\GuidedConditionService;
use App\Domain\Automation\Services\ThresholdPolicyWorkflowProjector;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Enums\MetricUnit;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use App\Domain\Shared\Models\Organization;
use Database\Factories\Domain\Automation\Models\AutomationThresholdPolicyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Number;

class ThresholdPolicy extends Model
{
    /** @use HasFactory<AutomationThresholdPolicyFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'threshold_policies';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'minimum_value' => 'decimal:3',
            'maximum_value' => 'decimal:3',
            'is_active' => 'bool',
            'cooldown_value' => 'int',
            'sort_order' => 'int',
            'guided_condition' => 'array',
            'condition_json_logic' => 'array',
            'legacy_metadata' => 'array',
        ];
    }

    protected static function newFactory(): AutomationThresholdPolicyFactory
    {
        return AutomationThresholdPolicyFactory::new();
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @return BelongsTo<DeviceChannel, $this> */
    public function deviceChannel(): BelongsTo
    {
        return $this->belongsTo(DeviceChannel::class);
    }

    /** @return BelongsTo<NotificationProfile, $this> */
    public function notificationProfile(): BelongsTo
    {
        return $this->belongsTo(NotificationProfile::class, 'notification_profile_id');
    }

    /** @return BelongsTo<AutomationWorkflow, $this> */
    public function managedWorkflow(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflow::class, 'managed_workflow_id');
    }

    /** @return HasMany<Alert, $this> */
    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class, 'threshold_policy_id');
    }

    public function profileParameterDefinition(): ?ProfileParameterDefinition
    {
        $this->loadMissing('deviceChannel');

        if (! is_numeric($this->device_channel_id) || ! is_string($this->parameter_key)) {
            return null;
        }

        return ProfileParameterDefinition::query()
            ->where('device_channel_id', (int) $this->device_channel_id)
            ->where('key', $this->parameter_key)
            ->where('is_active', true)
            ->first();
    }

    protected static function booted(): void
    {
        static::saving(static function (self $policy): void {
            $conditionModeValue = $policy->getAttribute('condition_mode');

            $conditionMode = is_string($conditionModeValue) && trim($conditionModeValue) !== ''
                ? trim($conditionModeValue)
                : null;
            $guidedCondition = $policy->guidedConditionPayload();
            $conditionJsonLogic = $policy->conditionJsonLogicPayload();
            $service = app(GuidedConditionService::class);

            if ($conditionMode === 'guided' && $guidedCondition !== null) {
                $normalizedGuidedCondition = $service->normalize($guidedCondition);

                $policy->setAttribute('condition_mode', 'guided');
                $policy->setAttribute('guided_condition', $normalizedGuidedCondition);
                $policy->setAttribute('condition_json_logic', $service->compile($normalizedGuidedCondition));

                return;
            }

            if ($conditionMode === 'json_logic' && $conditionJsonLogic !== null && $conditionJsonLogic !== []) {
                $policy->setAttribute('condition_mode', 'json_logic');
                $policy->setAttribute('guided_condition', $guidedCondition !== null
                    ? $service->normalize($guidedCondition)
                    : null);
                $policy->setAttribute('condition_json_logic', $conditionJsonLogic);

                return;
            }

            if ($policy->hasBounds()) {
                $normalizedCondition = $service->fromLegacyBounds(
                    minimumValue: $policy->minimumValue(),
                    maximumValue: $policy->maximumValue(),
                );

                $policy->setAttribute('condition_mode', $normalizedCondition['condition_mode']);
                $policy->setAttribute('guided_condition', $normalizedCondition['guided_condition']);
                $policy->setAttribute('condition_json_logic', $normalizedCondition['condition_json_logic']);
            }
        });

        static::updated(static function (self $policy): void {
            app(NormalizeThresholdPolicyAlerts::class)($policy);
        });

        static::deleted(static function (self $policy): void {
            app(ThresholdPolicyWorkflowProjector::class)->sync($policy);
        });

        static::restored(static function (self $policy): void {
            app(ThresholdPolicyWorkflowProjector::class)->sync($policy);
        });
    }

    public function hasMinimumValue(): bool
    {
        return $this->minimumValue() !== null;
    }

    public function hasMaximumValue(): bool
    {
        return $this->maximumValue() !== null;
    }

    public function hasBounds(): bool
    {
        return $this->hasMinimumValue() || $this->hasMaximumValue() || $this->hasCondition();
    }

    public function hasCondition(): bool
    {
        $conditionJsonLogic = $this->conditionJsonLogicPayload();

        return $conditionJsonLogic !== null && $conditionJsonLogic !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function resolvedConditionJsonLogic(): array
    {
        $conditionJsonLogic = $this->conditionJsonLogicPayload();

        if ($this->hasCondition()) {
            return $conditionJsonLogic ?? [];
        }

        $guidedCondition = $this->guidedConditionPayload();

        if (is_array($guidedCondition) && $guidedCondition !== []) {
            return app(GuidedConditionService::class)->compile($guidedCondition);
        }

        if ($this->hasMinimumValue() || $this->hasMaximumValue()) {
            return app(GuidedConditionService::class)->fromLegacyBounds(
                minimumValue: $this->minimumValue(),
                maximumValue: $this->maximumValue(),
            )['condition_json_logic'];
        }

        return [];
    }

    public function conditionLabel(?string $unit = null): string
    {
        $guidedCondition = $this->guidedConditionPayload();

        if (is_array($guidedCondition) && $guidedCondition !== []) {
            return app(GuidedConditionService::class)->label($guidedCondition, $unit ?? $this->profileParameterDefinition()?->unit);
        }

        $name = $this->getAttribute('name');

        return is_string($name) && trim($name) !== ''
            ? $name
            : 'Custom rule';
    }

    public function rangeLabel(?string $unit = null): string
    {
        $guidedCondition = $this->guidedConditionPayload();

        if (is_array($guidedCondition) && $guidedCondition !== []) {
            return $this->conditionLabel($unit);
        }

        $resolvedUnit = $unit ?? $this->profileParameterDefinition()?->unit;
        $minimumValue = $this->minimumValue();
        $maximumValue = $this->maximumValue();

        if ($minimumValue !== null && $maximumValue !== null) {
            return sprintf(
                '%s to %s',
                $this->formatThresholdValue($minimumValue, $resolvedUnit),
                $this->formatThresholdValue($maximumValue, $resolvedUnit),
            );
        }

        if ($minimumValue !== null) {
            return 'At least '.$this->formatThresholdValue($minimumValue, $resolvedUnit);
        }

        if ($maximumValue !== null) {
            return 'At most '.$this->formatThresholdValue($maximumValue, $resolvedUnit);
        }

        return 'No thresholds configured';
    }

    /**
     * @return array{value: int, unit: string}
     */
    public function cooldown(): array
    {
        return [
            'value' => max(1, (int) $this->cooldown_value),
            'unit' => in_array($this->cooldown_unit, ['minute', 'hour', 'day'], true)
                ? (string) $this->cooldown_unit
                : 'day',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function guidedConditionPayload(): ?array
    {
        $guidedCondition = $this->getAttribute('guided_condition');

        if (! is_array($guidedCondition)) {
            return null;
        }

        /** @var array<string, mixed> $guidedCondition */
        return $guidedCondition;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function conditionJsonLogicPayload(): ?array
    {
        $conditionJsonLogic = $this->getAttribute('condition_json_logic');

        if (! is_array($conditionJsonLogic)) {
            return null;
        }

        /** @var array<string, mixed> $conditionJsonLogic */
        return $conditionJsonLogic;
    }

    private function minimumValue(): ?float
    {
        $minimumValue = $this->getAttribute('minimum_value');

        return is_numeric($minimumValue) ? (float) $minimumValue : null;
    }

    private function maximumValue(): ?float
    {
        $maximumValue = $this->getAttribute('maximum_value');

        return is_numeric($maximumValue) ? (float) $maximumValue : null;
    }

    private function formatThresholdValue(float $value, ?string $unit = null): string
    {
        $formattedNumber = Number::format($value, maxPrecision: 3);
        $formattedValue = is_string($formattedNumber)
            ? (str_contains($formattedNumber, '.')
                ? rtrim(rtrim($formattedNumber, '0'), '.')
                : $formattedNumber)
            : (string) $value;

        if (! is_string($unit) || trim($unit) === '') {
            return $formattedValue;
        }

        $resolvedUnit = match ($unit) {
            MetricUnit::Celsius->value => '°C',
            MetricUnit::Percent->value => '%',
            MetricUnit::Volts->value => 'V',
            MetricUnit::Amperes->value => 'A',
            MetricUnit::KilowattHours->value => 'kWh',
            MetricUnit::Watts->value => 'W',
            MetricUnit::Seconds->value => 's',
            MetricUnit::DecibelMilliwatts->value => 'dBm',
            MetricUnit::RevolutionsPerMinute->value => 'RPM',
            MetricUnit::Litres->value => 'L',
            MetricUnit::CubicMeters->value => 'm³',
            MetricUnit::LitersPerMinute->value => 'L/min',
            default => trim($unit),
        };

        return $formattedValue.$resolvedUnit;
    }
}
