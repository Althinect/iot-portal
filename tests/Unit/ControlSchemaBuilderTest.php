<?php

declare(strict_types=1);

use App\Domain\DeviceControl\Services\ControlSchemaBuilder;
use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('builds dynamic control schema from parameter definitions', function (): void {
    $channel = DeviceChannel::factory()->command()->create();

    ProfileParameterDefinition::factory()->create([
        'device_channel_id' => $channel->id,
        'key' => 'brightness_level',
        'label' => 'Brightness',
        'type' => ParameterDataType::Integer,
        'default_value' => 5,
        'validation_rules' => ['min' => 0, 'max' => 10],
        'control_ui' => ['widget' => 'slider', 'step' => 1],
        'sequence' => 1,
        'is_active' => true,
        'mutation_expression' => null,
    ]);

    ProfileParameterDefinition::factory()->create([
        'device_channel_id' => $channel->id,
        'key' => 'light_state',
        'label' => 'Light State',
        'type' => ParameterDataType::Boolean,
        'default_value' => false,
        'control_ui' => ['widget' => 'toggle'],
        'sequence' => 2,
        'is_active' => true,
        'mutation_expression' => null,
    ]);

    ProfileParameterDefinition::factory()->create([
        'device_channel_id' => $channel->id,
        'key' => 'mode',
        'label' => 'Mode',
        'type' => ParameterDataType::String,
        'default_value' => 'auto',
        'validation_rules' => ['enum' => ['auto', 'manual']],
        'sequence' => 3,
        'is_active' => true,
        'mutation_expression' => null,
    ]);

    ProfileParameterDefinition::factory()->create([
        'device_channel_id' => $channel->id,
        'key' => 'apply',
        'label' => 'Apply',
        'type' => ParameterDataType::Boolean,
        'default_value' => false,
        'control_ui' => ['widget' => 'button', 'button_value' => true],
        'sequence' => 4,
        'is_active' => true,
        'mutation_expression' => null,
    ]);

    ProfileParameterDefinition::factory()->create([
        'device_channel_id' => $channel->id,
        'key' => 'color_hex',
        'label' => 'Color',
        'type' => ParameterDataType::String,
        'default_value' => '#ff0000',
        'validation_rules' => ['regex' => '/^#([A-Fa-f0-9]{6})$/'],
        'control_ui' => ['widget' => 'color'],
        'sequence' => 5,
        'is_active' => true,
        'mutation_expression' => null,
    ]);

    /** @var ControlSchemaBuilder $builder */
    $builder = app(ControlSchemaBuilder::class);

    $schema = $builder->buildForChannel($channel);
    $defaults = $builder->defaultControlValues($channel);

    expect($schema)->toHaveCount(5)
        ->and($schema[0]['widget'])->toBe('slider')
        ->and($schema[1]['widget'])->toBe('toggle')
        ->and($schema[2]['widget'])->toBe('select')
        ->and($schema[3]['widget'])->toBe('button')
        ->and($schema[3]['button_value'])->toBeTrue()
        ->and($schema[4]['widget'])->toBe('color')
        ->and($defaults)->toBe([
            'brightness_level' => 5,
            'light_state' => false,
            'mode' => 'auto',
            'apply' => false,
            'color_hex' => '#ff0000',
        ]);
});
