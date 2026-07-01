<?php

declare(strict_types=1);

namespace Database\Factories\Domain\DeviceControl\Models;

use App\Domain\DeviceControl\Models\DeviceDesiredChannelState;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeviceDesiredChannelState>
 */
class DeviceDesiredChannelStateFactory extends Factory
{
    protected $model = DeviceDesiredChannelState::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'device_channel_id' => DeviceChannel::factory()->command(),
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
