<?php

declare(strict_types=1);

use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use Tests\TestCase;

uses(TestCase::class);

it('extracts values from payloads using json path', function (): void {
    $definition = new ProfileParameterDefinition([
        'json_path' => '$.status.temp',
        'type' => ParameterDataType::Decimal,
    ]);

    $payload = [
        'status' => [
            'temp' => 22.5,
        ],
    ];

    expect($definition->extractValue($payload))->toBe(22.5);
});

it('applies JsonLogic mutation expressions to values', function (): void {
    $definition = new ProfileParameterDefinition([
        'json_path' => 'temp_c',
        'type' => ParameterDataType::Decimal,
        'mutation_expression' => [
            '+' => [
                ['*' => [
                    ['var' => 'val'],
                    1.8,
                ]],
                32,
            ],
        ],
    ]);

    $payload = ['temp_c' => 10];
    $value = $definition->extractValue($payload);

    expect($definition->mutateValue($value))->toBe(50.0);
});

it('validates required and critical flags', function (): void {
    $definition = new ProfileParameterDefinition([
        'required' => true,
        'is_critical' => true,
        'type' => ParameterDataType::Integer,
        'validation_error_code' => 'REQUIRED',
    ]);

    $result = $definition->validateValue(null);

    expect($result['is_valid'])->toBeFalse()
        ->and($result['is_critical'])->toBeTrue()
        ->and($result['error_code'])->toBe('REQUIRED');
});

it('returns explicit default value when set', function (): void {
    $definition = new ProfileParameterDefinition([
        'type' => ParameterDataType::Integer,
        'default_value' => 42,
    ]);

    expect($definition->resolvedDefaultValue())->toBe(42);
});

it('returns type-appropriate default when no explicit default is set', function (ParameterDataType $type, mixed $expected): void {
    $definition = new ProfileParameterDefinition([
        'type' => $type,
        'default_value' => null,
    ]);

    expect($definition->resolvedDefaultValue())->toBe($expected);
})->with([
    'integer' => [ParameterDataType::Integer, 0],
    'decimal' => [ParameterDataType::Decimal, 0.0],
    'boolean' => [ParameterDataType::Boolean, false],
    'string' => [ParameterDataType::String, ''],
    'json' => [ParameterDataType::Json, []],
]);

it('places a value into a flat payload using json_path', function (): void {
    $definition = new ProfileParameterDefinition([
        'json_path' => 'fan_speed',
        'type' => ParameterDataType::Integer,
    ]);

    $payload = $definition->placeValue([], 75);

    expect($payload)->toBe(['fan_speed' => 75]);
});

it('places a value into a nested payload using json_path', function (): void {
    $definition = new ProfileParameterDefinition([
        'json_path' => 'status.fan_speed',
        'type' => ParameterDataType::Integer,
    ]);

    $payload = $definition->placeValue(['status' => ['light' => true]], 50);

    expect($payload)->toBe([
        'status' => [
            'light' => true,
            'fan_speed' => 50,
        ],
    ]);
});

it('places a value into a nested payload using dollar-prefix json_path', function (): void {
    $definition = new ProfileParameterDefinition([
        'json_path' => '$.config.mode',
        'type' => ParameterDataType::String,
    ]);

    $payload = $definition->placeValue([], 'auto');

    expect($payload)->toBe([
        'config' => [
            'mode' => 'auto',
        ],
    ]);
});
