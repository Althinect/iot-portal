<?php

declare(strict_types=1);

namespace Database\Factories\Domain\DeviceManagement\Models;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Models\DeviceType;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
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
            'device_type_id' => DeviceType::factory(),
            'device_schema_version_id' => DeviceSchemaVersion::factory(),
            'parent_device_id' => null,
            'uuid' => (string) Str::uuid(),
            'name' => $this->faker->words(2, true),
            'external_id' => $this->faker->optional()->bothify('EXT-####'),
            'metadata' => [],
            'is_active' => true,
            'connection_state' => $this->faker->randomElement(['online', 'offline', 'unknown']),
            'last_seen_at' => $this->faker->optional()->dateTimeBetween('-7 days'),
        ];
    }
}
