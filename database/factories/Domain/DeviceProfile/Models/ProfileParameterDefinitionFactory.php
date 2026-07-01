<?php

declare(strict_types=1);

namespace Database\Factories\Domain\DeviceProfile\Models;

use App\Domain\DeviceProfile\Enums\ParameterCategory;
use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProfileParameterDefinition>
 */
class ProfileParameterDefinitionFactory extends Factory
{
    protected $model = ProfileParameterDefinition::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $dataType = $this->faker->randomElement(ParameterDataType::cases());
        $category = $this->faker->randomElement(ParameterCategory::cases());

        return [
            'device_channel_id' => DeviceChannel::factory(),
            'key' => $this->faker->unique()->slug(2),
            'label' => $this->faker->words(2, true),
            'json_path' => $this->faker->randomElement(['temp', 'status.temp', '$.status.temp']),
            'type' => $dataType,
            'category' => $category,
            'unit' => $dataType === ParameterDataType::Decimal ? $this->faker->randomElement(['Celsius', 'Percent', 'Watts']) : null,
            'required' => $this->faker->boolean(60),
            'is_critical' => $this->faker->boolean(20),
            'validation_rules' => [
                'min' => -40,
                'max' => 85,
            ],
            'control_ui' => null,
            'validation_error_code' => $this->faker->optional()->lexify('VAL_????'),
            'mutation_expression' => [
                '*' => [
                    ['var' => 'val'],
                    1.0,
                ],
            ],
            'sequence' => $this->faker->numberBetween(1, 10),
            'is_active' => $this->faker->boolean(90),
            'default_value' => null,
        ];
    }

    public function subscribe(): static
    {
        return $this->state(fn () => [
            'device_channel_id' => DeviceChannel::factory()->subscribe(),
            'json_path' => $this->faker->unique()->slug(1),
            'is_critical' => false,
            'validation_error_code' => null,
            'mutation_expression' => null,
            'default_value' => 0,
            'category' => ParameterCategory::Measurement,
            'control_ui' => null,
        ]);
    }
}
