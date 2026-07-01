<?php

declare(strict_types=1);

use App\Domain\DeviceControl\Services\CommandPayloadResolver;
use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('resolves command payload from control values and json paths', function (): void {
    $channel = DeviceChannel::factory()->command()->create();

    ProfileParameterDefinition::factory()->create([
        'device_channel_id' => $channel->id,
        'key' => 'fan_speed',
        'json_path' => 'control.fan_speed',
        'type' => ParameterDataType::Integer,
        'default_value' => 0,
        'validation_rules' => ['min' => 0, 'max' => 100],
        'sequence' => 1,
        'is_active' => true,
        'mutation_expression' => null,
    ]);

    ProfileParameterDefinition::factory()->create([
        'device_channel_id' => $channel->id,
        'key' => 'enabled',
        'json_path' => 'control.enabled',
        'type' => ParameterDataType::Boolean,
        'default_value' => false,
        'sequence' => 2,
        'is_active' => true,
        'mutation_expression' => null,
    ]);

    /** @var CommandPayloadResolver $resolver */
    $resolver = app(CommandPayloadResolver::class);

    $resolved = $resolver->resolveFromControls($channel, [
        'fan_speed' => '80',
        'enabled' => 'true',
    ]);

    expect($resolved['errors'])->toBe([])
        ->and($resolved['payload'])->toBe([
            'control' => [
                'fan_speed' => 80,
                'enabled' => true,
            ],
        ]);
});

it('returns validation errors for invalid payload values', function (): void {
    $channel = DeviceChannel::factory()->command()->create();

    ProfileParameterDefinition::factory()->create([
        'device_channel_id' => $channel->id,
        'key' => 'brightness_level',
        'json_path' => 'brightness_level',
        'type' => ParameterDataType::Integer,
        'default_value' => 0,
        'validation_rules' => ['min' => 0, 'max' => 10],
        'validation_error_code' => 'BRIGHTNESS_RANGE',
        'sequence' => 1,
        'is_active' => true,
        'mutation_expression' => null,
    ]);

    /** @var CommandPayloadResolver $resolver */
    $resolver = app(CommandPayloadResolver::class);

    $errors = $resolver->validatePayload($channel, [
        'brightness_level' => 50,
    ]);

    expect($errors)->toHaveKey('brightness_level', 'BRIGHTNESS_RANGE');
});

it('omits default button widget values unless they are explicitly triggered', function (): void {
    $channel = DeviceChannel::factory()->command()->create();

    ProfileParameterDefinition::factory()->create([
        'device_channel_id' => $channel->id,
        'key' => 'power',
        'json_path' => 'power',
        'type' => ParameterDataType::Boolean,
        'default_value' => false,
        'sequence' => 1,
        'is_active' => true,
        'mutation_expression' => null,
    ]);

    ProfileParameterDefinition::factory()->create([
        'device_channel_id' => $channel->id,
        'key' => 'send_now',
        'json_path' => 'send_now',
        'type' => ParameterDataType::Boolean,
        'default_value' => false,
        'required' => false,
        'control_ui' => [
            'widget' => 'button',
            'button_value' => true,
        ],
        'sequence' => 2,
        'is_active' => true,
        'mutation_expression' => null,
    ]);

    /** @var CommandPayloadResolver $resolver */
    $resolver = app(CommandPayloadResolver::class);

    $defaultButton = $resolver->resolveFromControls($channel, [
        'power' => true,
        'send_now' => false,
    ]);

    $triggeredButton = $resolver->resolveFromControls($channel, [
        'power' => true,
        'send_now' => true,
    ]);

    expect($defaultButton['errors'])->toBe([])
        ->and($defaultButton['payload'])->toBe([
            'power' => true,
        ])
        ->and($triggeredButton['errors'])->toBe([])
        ->and($triggeredButton['payload'])->toBe([
            'power' => true,
            'send_now' => true,
        ]);
});
