<?php

declare(strict_types=1);

namespace Database\Factories\Domain\DeviceControl\Models;

use App\Domain\DeviceControl\Models\DeviceDesiredState;
use App\Domain\DeviceManagement\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeviceDesiredState>
 */
class DeviceDesiredStateFactory extends Factory
{
    protected $model = DeviceDesiredState::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'desired_state' => [
                'brightness' => $this->faker->numberBetween(0, 100),
                'color' => $this->faker->hexColor,
            ],
            'reconciled_at' => null,
        ];
    }

    public function reconciled(): static
    {
        return $this->state(fn () => [
            'reconciled_at' => now(),
        ]);
    }
}
