<?php

declare(strict_types=1);

namespace App\Domain\DeviceProfile\DTO;

use App\Domain\DeviceProfile\Enums\ControlWidgetType;
use App\Domain\DeviceProfile\Enums\ParameterCategory;
use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Services\JsonLogicEvaluator;
use Stringable;

/**
 * Immutable parameter contract DTO carrying the runtime behaviour
 * (extraction, mutation, validation, payload placement) that previously
 * lived on the Eloquent ParameterDefinition model. The workflow is driven
 * entirely by this DTO so callers never touch loose arrays.
 */
final readonly class ParameterDefinitionData
{
    /**
     * @param  array<string, mixed>|null  $validationRules
     * @param  array<string, mixed>|null  $controlUi
     * @param  array<string, mixed>|null  $mutationExpression
     */
    public function __construct(
        public int $id,
        public string $key,
        public string $label,
        public string $jsonPath,
        public ParameterDataType $type,
        public ParameterCategory $category,
        public ?string $unit,
        public bool $required,
        public bool $isCritical,
        public bool $isActive,
        public int $sequence,
        public ?string $validationErrorCode,
        public ?array $validationRules,
        public ?array $controlUi,
        public ?array $mutationExpression,
        public mixed $defaultValue,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function extractValue(array $payload): mixed
    {
        $path = $this->normalizeJsonPath($this->jsonPath);

        if ($path === null) {
            return null;
        }

        return data_get($payload, $path);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function placeValue(array $payload, mixed $value): array
    {
        $path = $this->normalizeJsonPath($this->jsonPath);

        if ($path === null) {
            return $payload;
        }

        data_set($payload, $path, $value);

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    public function mutateValue(mixed $value): mixed
    {
        if (! is_array($this->mutationExpression) || $this->mutationExpression === []) {
            return $value;
        }

        return (new JsonLogicEvaluator)->evaluate($this->mutationExpression, ['val' => $value]);
    }

    public function resolvedDefaultValue(): mixed
    {
        if ($this->defaultValue !== null) {
            return $this->defaultValue;
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
    public function resolvedValidationRules(): array
    {
        return is_array($this->validationRules) ? $this->validationRules : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function resolvedControlUi(): array
    {
        return is_array($this->controlUi) ? $this->controlUi : [];
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

        $rules = $this->resolvedValidationRules();

        if (array_key_exists('min', $rules) && is_numeric($value) && $value < $rules['min']) {
            return $this->invalidResult();
        }

        if (array_key_exists('max', $rules) && is_numeric($value) && $value > $rules['max']) {
            return $this->invalidResult();
        }

        if (array_key_exists('regex', $rules) && is_string($value)) {
            if (! is_string($rules['regex']) || @preg_match($rules['regex'], '') === false) {
                return $this->invalidResult();
            }

            if (! preg_match($rules['regex'], $value)) {
                return $this->invalidResult();
            }
        }

        if (array_key_exists('enum', $rules) && is_array($rules['enum']) && ! in_array($value, $rules['enum'], true)) {
            return $this->invalidResult();
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

        if ($normalized === '' || $normalized === '$') {
            return null;
        }

        if (str_starts_with($normalized, '$.')) {
            $normalized = substr($normalized, 2);
        }

        return $normalized;
    }

    private function matchesDataType(mixed $value): bool
    {
        return match ($this->type) {
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
            'error_code' => $this->validationErrorCode,
            'is_critical' => $this->isCritical,
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

        $searchableContent = strtolower("{$this->key} {$this->label} {$this->jsonPath}");

        if (str_contains($searchableContent, 'color') || str_contains($searchableContent, 'colour')) {
            return ControlWidgetType::Color;
        }

        if (is_array($rules['enum'] ?? null) && $rules['enum'] !== []) {
            return ControlWidgetType::Select;
        }

        return ControlWidgetType::Text;
    }

    /**
     * @return array<int, array{value: string, label: string, color: string}>
     */
    public function resolvedStateMappings(): array
    {
        $controlUi = $this->resolvedControlUi();
        $explicitMappings = $controlUi['state_mappings'] ?? null;

        if (is_array($explicitMappings)) {
            $normalizedExplicitMappings = $this->normalizeStateMappings(array_values($explicitMappings));

            if ($normalizedExplicitMappings !== []) {
                return $normalizedExplicitMappings;
            }
        }

        $enum = $this->resolvedValidationRules()['enum'] ?? null;

        if (is_array($enum)) {
            $enumMappings = array_map(
                static fn (mixed $value): array => [
                    'value' => $value,
                    'label' => is_scalar($value) || $value instanceof Stringable ? (string) $value : '',
                    'color' => '',
                ],
                $enum,
            );

            $normalizedEnumMappings = $this->normalizeStateMappings($enumMappings);

            if ($normalizedEnumMappings !== []) {
                return $normalizedEnumMappings;
            }
        }

        if ($this->type === ParameterDataType::Boolean) {
            return [
                ['value' => 'false', 'label' => 'OFF', 'color' => '#ef4444'],
                ['value' => 'true', 'label' => 'ON', 'color' => '#22c55e'],
            ];
        }

        return [];
    }

    /**
     * @param  array<int, mixed>  $mappings
     * @return array<int, array{value: string, label: string, color: string}>
     */
    private function normalizeStateMappings(array $mappings): array
    {
        $normalized = [];
        $seen = [];
        $palette = ['#22c55e', '#ef4444', '#f59e0b', '#3b82f6', '#8b5cf6', '#06b6d4', '#64748b'];

        foreach (array_values($mappings) as $index => $mapping) {
            if (! is_array($mapping)) {
                continue;
            }

            $value = $this->normalizeStateMappingValue($mapping['value'] ?? null);

            if ($value === '' || in_array($value, $seen, true)) {
                continue;
            }

            $seen[] = $value;
            $normalized[] = [
                'value' => $value,
                'label' => is_string($mapping['label'] ?? null) && trim((string) $mapping['label']) !== ''
                    ? trim((string) $mapping['label'])
                    : $value,
                'color' => is_string($mapping['color'] ?? null) && trim((string) $mapping['color']) !== ''
                    ? trim((string) $mapping['color'])
                    : $palette[$index % count($palette)],
            ];
        }

        return $normalized;
    }

    private function normalizeStateMappingValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            $normalized = rtrim(rtrim(sprintf('%.12F', $value), '0'), '.');

            return $normalized === '' ? '0' : $normalized;
        }

        if (is_string($value)) {
            return trim($value);
        }

        if ($value instanceof Stringable) {
            return trim((string) $value);
        }

        return '';
    }
}
