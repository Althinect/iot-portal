<?php

declare(strict_types=1);

namespace Database\Factories\Domain\DataIngestion\Models;

use App\Domain\DataIngestion\Models\DeviceSignalBinding;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceSchema\Models\ParameterDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeviceSignalBinding>
 */
class DeviceSignalBindingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'parameter_definition_id' => ParameterDefinition::factory(),
            'source_topic' => 'migration/source/imoni/'.$this->faker->numerify('###############').'/00/telemetry',
            'source_json_path' => '$.io_'.$this->faker->numberBetween(1, 31).'_value',
            'source_adapter' => 'imoni',
            'sequence' => 0,
            'is_active' => true,
            'metadata' => [],
        ];
    }
}
