<?php

declare(strict_types=1);

namespace Database\Factories\Domain\DeviceSchema\Models;

use App\Domain\DeviceSchema\Models\DeviceSchema;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeviceSchemaVersion>
 */
class DeviceSchemaVersionFactory extends Factory
{
    protected $model = DeviceSchemaVersion::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'device_schema_id' => DeviceSchema::factory(),
            'version' => 1,
            'status' => 'draft',
            'notes' => null,
            'firmware_template' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => 'active',
        ]);
    }
}
