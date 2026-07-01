<?php

declare(strict_types=1);

namespace App\Domain\DeviceProfile\DTO;

use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Services\JsonLogicEvaluator;

/**
 * Immutable derived parameter contract DTO carrying derivation behaviour
 * (JsonLogic evaluation, dependency resolution, cycle detection).
 */
final readonly class DerivedParameterDefinitionData
{
    /**
     * @param  array<string, mixed>  $expression
     * @param  array<int, mixed>|null  $dependencies
     */
    public function __construct(
        public int $id,
        public string $key,
        public string $label,
        public ParameterDataType $dataType,
        public ?string $unit,
        public array $expression,
        public ?array $dependencies,
        public ?string $jsonPath,
    ) {}

    /**
     * @param  array<string, mixed>  $inputs
     */
    public function evaluate(array $inputs): mixed
    {
        return (new JsonLogicEvaluator)->evaluate($this->expression, $inputs);
    }

    /**
     * @return array<int, string>
     */
    public function resolvedDependencies(): array
    {
        if (is_array($this->dependencies) && $this->dependencies !== []) {
            return array_values(array_unique(array_filter(
                $this->dependencies,
                fn (mixed $value): bool => is_string($value) && $value !== '',
            )));
        }

        return array_values(array_unique(self::extractVariables($this->expression)));
    }

    /**
     * @param  array<int, string>  $availableKeys
     * @return array{is_valid: bool, missing: array<int, string>}
     */
    public function validateDependencies(array $availableKeys): array
    {
        $dependencies = $this->resolvedDependencies();
        $missing = array_values(array_diff($dependencies, $availableKeys));

        return [
            'is_valid' => $missing === [],
            'missing' => $missing,
        ];
    }

    /**
     * @param  array<int, self>  $definitions
     * @return array{has_cycle: bool, cycles: array<int, string>}
     */
    public static function detectCircularDependencies(array $definitions): array
    {
        $graph = [];

        foreach ($definitions as $definition) {
            $graph[$definition->key] = $definition->resolvedDependencies();
        }

        $visited = [];
        $stack = [];
        $cycles = [];

        foreach (array_keys($graph) as $node) {
            if (self::visitNode($node, $graph, $visited, $stack, $cycles)) {
                break;
            }
        }

        return [
            'has_cycle' => $cycles !== [],
            'cycles' => $cycles,
        ];
    }

    /**
     * @param  array<string, array<int, string>>  $graph
     * @param  array<string, bool>  $visited
     * @param  array<string, bool>  $stack
     * @param  array<int, string>  $cycles
     */
    private static function visitNode(string $node, array $graph, array &$visited, array &$stack, array &$cycles): bool
    {
        if (($stack[$node] ?? false) === true) {
            $cycles[] = $node;

            return true;
        }

        if (($visited[$node] ?? false) === true) {
            return false;
        }

        $visited[$node] = true;
        $stack[$node] = true;

        foreach ($graph[$node] ?? [] as $neighbor) {
            if (! array_key_exists($neighbor, $graph)) {
                continue;
            }

            if (self::visitNode($neighbor, $graph, $visited, $stack, $cycles)) {
                return true;
            }
        }

        $stack[$node] = false;

        return false;
    }

    /**
     * @param  array<mixed, mixed>  $expression
     * @return array<int, string>
     */
    private static function extractVariables(array $expression): array
    {
        $variables = [];

        foreach ($expression as $key => $value) {
            if ($key === 'var') {
                $variables = array_merge($variables, self::normalizeVarValue($value));

                continue;
            }

            if (is_array($value)) {
                $variables = array_merge($variables, self::extractVariables($value));
            }
        }

        return array_values(array_unique($variables));
    }

    /**
     * @return array<int, string>
     */
    private static function normalizeVarValue(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            return [$value];
        }

        if (is_array($value)) {
            $path = $value[0] ?? null;

            if (is_string($path) && $path !== '') {
                return [$path];
            }
        }

        return [];
    }
}
