<?php

declare(strict_types=1);

namespace Database\Factories\Domain\DeviceSchema\Models;

use App\Domain\DeviceManagement\Models\DeviceType;
use App\Domain\DeviceSchema\Models\DeviceSchema;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeviceSchema>
 */
class DeviceSchemaFactory extends Factory
{
    protected $model = DeviceSchema::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'device_type_id' => DeviceType::factory(),
            'name' => 'Schema '.strtoupper(bin2hex(random_bytes(2))),
        ];
    }

    public function forDeviceType(DeviceType $deviceType): static
    {
        return $this->state(fn () => [
            'device_type_id' => $deviceType->id,
        ]);
    }
}
