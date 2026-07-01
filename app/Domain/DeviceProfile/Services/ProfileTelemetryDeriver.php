<?php

declare(strict_types=1);

namespace App\Domain\DeviceProfile\Services;

use App\Domain\DeviceProfile\DTO\DerivationResult;
use App\Domain\DeviceProfile\DTO\DerivedParameterDefinitionData;
use Illuminate\Support\Collection;

class ProfileTelemetryDeriver
{
    /**
     * Derive computed parameters from the mutated values, resolving
     * dependencies iteratively (derived parameters may depend on other
     * derived parameters).
     *
     * @param  array<string, mixed>  $mutatedValues
     * @param  Collection<int, DerivedParameterDefinitionData>  $derivedParameters
     */
    public function derive(array $mutatedValues, Collection $derivedParameters): DerivationResult
    {
        /** @var array<string, DerivedParameterDefinitionData> $pending */
        $pending = $derivedParameters->keyBy('key')->all();

        $resolved = $mutatedValues;
        $derivedValues = [];

        $maxIterations = count($pending);
        $iterations = 0;

        while ($pending !== [] && $iterations < $maxIterations) {
            $progress = false;

            foreach ($pending as $key => $definition) {
                $dependencies = $definition->resolvedDependencies();

                if (array_diff($dependencies, array_keys($resolved)) !== []) {
                    continue;
                }

                $value = $definition->evaluate($resolved);

                $resolved[$key] = $value;
                $derivedValues[$key] = $value;

                unset($pending[$key]);
                $progress = true;
            }

            if (! $progress) {
                break;
            }

            $iterations++;
        }

        return new DerivationResult(
            derivedValues: $derivedValues,
            finalValues: array_merge($mutatedValues, $derivedValues),
        );
    }
}
