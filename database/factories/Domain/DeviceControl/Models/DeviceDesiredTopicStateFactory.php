<?php

declare(strict_types=1);

namespace Database\Factories\Domain\DeviceControl\Models;

use App\Domain\DeviceControl\Models\DeviceDesiredTopicState;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeviceDesiredTopicState>
 */
class DeviceDesiredTopicStateFactory extends Factory
{
    protected $model = DeviceDesiredTopicState::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'schema_version_topic_id' => SchemaVersionTopic::factory()->subscribe(),
            'desired_payload' => [
                'enabled' => $this->faker->boolean(),
            ],
            'correlation_id' => $this->faker->optional()->uuid(),
            'reconciled_at' => null,
        ];
    }

    public function reconciled(): static
    {
        return $this->state(fn (): array => [
            'reconciled_at' => now(),
        ]);
    }
}
