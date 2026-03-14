<?php

declare(strict_types=1);

namespace Database\Factories\Domain\DeviceManagement\Models;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Models\TemporaryDevice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Domain\DeviceManagement\Models\TemporaryDevice>
 */
class TemporaryDeviceFactory extends Factory
{
    protected $model = TemporaryDevice::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'expires_at' => now()->addDay(),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subHour(),
        ]);
    }

    public function unexpired(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->addDay(),
        ]);
    }
}
