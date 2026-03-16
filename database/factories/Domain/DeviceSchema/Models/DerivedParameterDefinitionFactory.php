<?php

declare(strict_types=1);

namespace Database\Factories\Domain\DeviceSchema\Models;

use App\Domain\DeviceSchema\Enums\ParameterDataType;
use App\Domain\DeviceSchema\Models\DerivedParameterDefinition;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DerivedParameterDefinition>
 */
class DerivedParameterDefinitionFactory extends Factory
{
    protected $model = DerivedParameterDefinition::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'device_schema_version_id' => DeviceSchemaVersion::factory(),
            'key' => $this->faker->unique()->slug(2),
            'label' => $this->faker->words(2, true),
            'data_type' => $this->faker->randomElement(ParameterDataType::cases()),
            'unit' => $this->faker->optional()->randomElement(['Celsius', 'Percent', 'Watts']),
            'expression' => [
                '/' => [
                    ['+' => [
                        ['var' => 'V1'],
                        ['var' => 'V2'],
                    ]],
                    2,
                ],
            ],
            'dependencies' => ['V1', 'V2'],
            'json_path' => $this->faker->optional()->randomElement(['computed.avg_voltage', '$.computed.avg_voltage']),
        ];
    }
}
