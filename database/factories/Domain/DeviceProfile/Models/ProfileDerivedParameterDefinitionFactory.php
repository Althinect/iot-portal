<?php

declare(strict_types=1);

namespace Database\Factories\Domain\DeviceProfile\Models;

use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Models\ProfileDerivedParameterDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProfileDerivedParameterDefinition>
 */
class ProfileDerivedParameterDefinitionFactory extends Factory
{
    protected $model = ProfileDerivedParameterDefinition::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'device_profile_version_id' => DeviceProfileVersion::factory(),
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
