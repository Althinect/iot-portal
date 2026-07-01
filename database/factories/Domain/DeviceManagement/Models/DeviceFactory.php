<?php

declare(strict_types=1);

namespace Database\Factories\Domain\DeviceManagement\Models;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\Shared\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    protected $model = Device::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'device_profile_version_id' => DeviceProfileVersion::factory(),
            'parent_device_id' => null,
            'is_virtual' => false,
            'uuid' => (string) Str::uuid(),
            'name' => $this->faker->words(2, true),
            'external_id' => $this->faker->optional()->bothify('EXT-####'),
            'metadata' => [],
            'is_active' => true,
            'connection_state' => 'offline',
            'last_seen_at' => null,
        ];
    }

    public function virtual(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_virtual' => true,
            'parent_device_id' => null,
        ]);
    }
}
