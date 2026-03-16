<?php

declare(strict_types=1);

namespace App\Domain\DeviceSchema\Models;

use App\Domain\DataIngestion\Models\DeviceSignalBinding;
use App\Domain\DeviceSchema\Enums\ControlWidgetType;
use App\Domain\DeviceSchema\Enums\ParameterCategory;
use App\Domain\DeviceSchema\Enums\ParameterDataType;
use App\Domain\DeviceSchema\Services\JsonLogicEvaluator;
use Database\Factories\Domain\DeviceSchema\Models\ParameterDefinitionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $key
 * @property string $label
 * @property int $schema_version_topic_id
 * @property ParameterDataType $type
 * @property ParameterCategory $category
 * @property array<string, mixed>|null $validation_rules
 * @property array<string, mixed>|null $control_ui
 */
class ParameterDefinition extends Model
{
    /** @use HasFactory<ParameterDefinitionFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ParameterDataType::class,
            'category' => ParameterCategory::class,
            'required' => 'bool',
            'is_critical' => 'bool',
            'is_active' => 'bool',
            'validation_rules' => 'array',
            'control_ui' => 'array',
            'mutation_expression' => 'array',
            'default_value' => 'json',
        ];
    }

    /**
     * @return BelongsTo<SchemaVersionTopic, $this>
     */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(SchemaVersionTopic::class, 'schema_version_topic_id');
    }

    /**
     * @return HasMany<DeviceSignalBinding, $this>
     */
    public function signalBindings(): HasMany
    {
        return $this->hasMany(DeviceSignalBinding::class);
    }

    /**
     * Get the resolved default value, falling back to a type-appropriate default.
     */
    public function resolvedDefaultValue(): mixed
    {
        if ($this->default_value !== null) {
            return $this->default_value;
        }

        return match ($this->type) {
            ParameterDataType::Integer => 0,
            ParameterDataType::Decimal => 0.0,
            ParameterDataType::Boolean => false,
            ParameterDataType::String => '',
            ParameterDataType::Json => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function resolvedControlUi(): array
    {
        $controlUi = $this->getAttribute('control_ui');

        return is_array($controlUi) ? $controlUi : [];
    }

    public function resolvedWidgetType(): ControlWidgetType
    {
        $explicitWidget = $this->resolvedControlUi()['widget'] ?? null;

        if (is_string($explicitWidget)) {
            $widget = ControlWidgetType::tryFrom($explicitWidget);

            if ($widget instanceof ControlWidgetType) {
                return $widget;
            }
        }

        $rules = $this->resolvedValidationRules();

        if (
            in_array($this->type, [ParameterDataType::Integer, ParameterDataType::Decimal], true)
            && array_key_exists('min', $rules)
            && array_key_exists('max', $rules)
            && is_numeric($rules['min'])
            && is_numeric($rules['max'])
        ) {
            return ControlWidgetType::Slider;
        }

        return match ($this->type) {
            ParameterDataType::Boolean => ControlWidgetType::Toggle,
            ParameterDataType::String => $this->resolveStringWidget($rules),
            ParameterDataType::Integer,
            ParameterDataType::Decimal => ControlWidgetType::Number,
            ParameterDataType::Json => ControlWidgetType::Json,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function resolvedValidationRules(): array
    {
        $rules = $this->getAttribute('validation_rules');

        return is_array($rules) ? $rules : [];
    }

    /**
     * @return array<int|string, string>
     */
    public function resolvedSelectOptions(): array
    {
        $rules = $this->resolvedValidationRules();
        $enum = $rules['enum'] ?? null;

        if (! is_array($enum)) {
            return [];
        }

        $options = [];

        foreach ($enum as $value) {
            if (! is_scalar($value) && ! $value instanceof \Stringable) {
                continue;
            }

            $option = (string) $value;
            $options[$option] = $option;
        }

        return $options;
    }

    /**
     * @return array{min: int|float|null, max: int|float|null, step: int|float}
     */
    public function resolvedNumericRange(): array
    {
        $rules = $this->resolvedValidationRules();
        $ui = $this->resolvedControlUi();

        $min = isset($ui['min']) && is_numeric($ui['min'])
            ? +$ui['min']
            : (isset($rules['min']) && is_numeric($rules['min']) ? +$rules['min'] : null);

        $max = isset($ui['max']) && is_numeric($ui['max'])
            ? +$ui['max']
            : (isset($rules['max']) && is_numeric($rules['max']) ? +$rules['max'] : null);

        $step = isset($ui['step']) && is_numeric($ui['step'])
            ? +$ui['step']
            : ($this->type === ParameterDataType::Decimal ? 0.1 : 1);

        return [
            'min' => $min,
            'max' => $max,
            'step' => $step,
        ];
    }

    public function resolvedButtonValue(): mixed
    {
        $ui = $this->resolvedControlUi();

        if (array_key_exists('button_value', $ui)) {
            return $ui['button_value'];
        }

        return match ($this->type) {
            ParameterDataType::Boolean => true,
            ParameterDataType::Integer => 1,
            ParameterDataType::Decimal => 1.0,
            ParameterDataType::String => 'pressed',
            ParameterDataType::Json => ['pressed' => true],
        };
    }

    /**
     * Place a value into a payload array at this parameter's json_path.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function placeValue(array $payload, mixed $value): array
    {
        $path = $this->normalizeJsonPath($this->json_path);

        if ($path === null) {
            return $payload;
        }

        data_set($payload, $path, $value);

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function extractValue(array $payload): mixed
    {
        $path = $this->normalizeJsonPath($this->json_path);

        if ($path === null) {
            return null;
        }

        return data_get($payload, $path);
    }

    public function mutateValue(mixed $value): mixed
    {
        $mutationExpression = $this->getAttribute('mutation_expression');

        if (! is_array($mutationExpression) || $mutationExpression === []) {
            return $value;
        }

        $evaluator = new JsonLogicEvaluator;

        return $evaluator->evaluate($mutationExpression, [
            'val' => $value,
        ]);
    }

    /**
     * @return array{is_valid: bool, error_code: string|null, is_critical: bool}
     */
    public function validateValue(mixed $value): array
    {
        if ($this->required && ($value === null || $value === '')) {
            return $this->invalidResult();
        }

        if ($value === null || $value === '') {
            return $this->validResult();
        }

        if (! $this->matchesDataType($value)) {
            return $this->invalidResult();
        }

        $rules = $this->getAttribute('validation_rules');

        if (! is_array($rules)) {
            $rules = [];
        }

        if (array_key_exists('min', $rules) && is_numeric($value)) {
            if ($value < $rules['min']) {
                return $this->invalidResult();
            }
        }

        if (array_key_exists('max', $rules) && is_numeric($value)) {
            if ($value > $rules['max']) {
                return $this->invalidResult();
            }
        }

        if (array_key_exists('regex', $rules) && is_string($value)) {
            if (! is_string($rules['regex']) || @preg_match($rules['regex'], '') === false) {
                return $this->invalidResult();
            }

            if (! preg_match($rules['regex'], $value)) {
                return $this->invalidResult();
            }
        }

        if (array_key_exists('enum', $rules) && is_array($rules['enum'])) {
            if (! in_array($value, $rules['enum'], true)) {
                return $this->invalidResult();
            }
        }

        return $this->validResult();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{value: mixed, mutated: mixed, validation: array{is_valid: bool, error_code: string|null, is_critical: bool}}
     */
    public function evaluatePayload(array $payload): array
    {
        $value = $this->extractValue($payload);
        $mutated = $this->mutateValue($value);
        $validation = $this->validateValue($mutated);

        return [
            'value' => $value,
            'mutated' => $mutated,
            'validation' => $validation,
        ];
    }

    private function normalizeJsonPath(string $path): ?string
    {
        $normalized = trim($path);

        if ($normalized === '') {
            return null;
        }

        if ($normalized === '$') {
            return null;
        }

        if (str_starts_with($normalized, '$.')) {
            $normalized = substr($normalized, 2);
        }

        return $normalized;
    }

    private function matchesDataType(mixed $value): bool
    {
        $type = $this->getAttribute('type');

        if (! $type instanceof ParameterDataType) {
            if (! is_string($type) && ! is_int($type)) {
                return false;
            }

            $type = ParameterDataType::tryFrom((string) $type);

            if ($type === null) {
                return false;
            }
        }

        return match ($type) {
            ParameterDataType::Integer => is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1),
            ParameterDataType::Decimal => is_numeric($value),
            ParameterDataType::Boolean => is_bool($value) || in_array($value, ['true', 'false', 0, 1, '0', '1'], true),
            ParameterDataType::String => is_string($value),
            ParameterDataType::Json => is_array($value) || is_object($value),
        };
    }

    /**
     * @return array{is_valid: bool, error_code: string|null, is_critical: bool}
     */
    private function invalidResult(): array
    {
        return [
            'is_valid' => false,
            'error_code' => $this->validation_error_code,
            'is_critical' => $this->is_critical,
        ];
    }

    /**
     * @return array{is_valid: bool, error_code: string|null, is_critical: bool}
     */
    private function validResult(): array
    {
        return [
            'is_valid' => true,
            'error_code' => null,
            'is_critical' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private function resolveStringWidget(array $rules): ControlWidgetType
    {
        $regex = $rules['regex'] ?? null;

        if (
            is_string($regex)
            && str_contains(strtolower($regex), '#')
            && str_contains(strtolower($regex), 'a-f')
        ) {
            return ControlWidgetType::Color;
        }

        $searchableContent = strtolower("{$this->key} {$this->label} {$this->json_path}");

        if (str_contains($searchableContent, 'color') || str_contains($searchableContent, 'colour')) {
            return ControlWidgetType::Color;
        }

        if (is_array($rules['enum'] ?? null) && $rules['enum'] !== []) {
            return ControlWidgetType::Select;
        }

        return ControlWidgetType::Text;
    }
}
