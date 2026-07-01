<?php

declare(strict_types=1);

use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Models\ProfileDerivedParameterDefinition;
use Tests\TestCase;

uses(TestCase::class);

it('evaluates JsonLogic expressions using transformed inputs', function (): void {
    $definition = new ProfileDerivedParameterDefinition([
        'key' => 'avg_voltage',
        'data_type' => ParameterDataType::Decimal,
        'expression' => [
            '/' => [
                ['+' => [
                    ['var' => 'V1'],
                    ['var' => 'V2'],
                ]],
                2,
            ],
        ],
    ]);

    $result = $definition->evaluate([
        'V1' => 220,
        'V2' => 230,
    ]);

    expect($result)->toBe(225.0);
});

it('validates that dependencies exist in the profile version', function (): void {
    $definition = new ProfileDerivedParameterDefinition([
        'key' => 'heat_index',
        'data_type' => ParameterDataType::Decimal,
        'expression' => [
            '+' => [
                ['var' => 'temp_c'],
                ['var' => 'humidity'],
            ],
        ],
        'dependencies' => ['temp_c', 'humidity'],
    ]);

    $result = $definition->validateDependencies(['temp_c']);

    expect($result['is_valid'])->toBeFalse()
        ->and($result['missing'])->toBe(['humidity']);
});

it('detects circular dependencies between derived parameters', function (): void {
    $first = new ProfileDerivedParameterDefinition([
        'key' => 'A',
        'data_type' => ParameterDataType::Decimal,
        'dependencies' => ['B'],
        'expression' => ['var' => 'B'],
    ]);

    $second = new ProfileDerivedParameterDefinition([
        'key' => 'B',
        'data_type' => ParameterDataType::Decimal,
        'dependencies' => ['A'],
        'expression' => ['var' => 'A'],
    ]);

    $result = ProfileDerivedParameterDefinition::detectCircularDependencies([$first, $second]);

    expect($result['has_cycle'])->toBeTrue();
});
