<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Telemetry\Models;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Domain\Telemetry\Enums\ValidationStatus;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeviceTelemetryLog>
 */
class DeviceTelemetryLogFactory extends Factory
{
    protected $model = DeviceTelemetryLog::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $receivedAt = $this->faker->dateTimeBetween('-1 day');
        $recordedAt = $this->faker->dateTimeBetween('-1 day', $receivedAt);

        return [
            'device_id' => Device::factory(),
            'device_schema_version_id' => DeviceSchemaVersion::factory(),
            'schema_version_topic_id' => SchemaVersionTopic::factory(),
            'raw_payload' => [
                'temp' => $this->faker->randomFloat(2, -10, 40),
                'voltage' => $this->faker->randomFloat(2, 100, 250),
            ],
            'transformed_values' => [
                'temp' => $this->faker->randomFloat(2, -10, 40),
                'voltage' => $this->faker->randomFloat(2, 100, 250),
            ],
            'validation_status' => $this->faker->randomElement(ValidationStatus::cases()),
            'recorded_at' => $recordedAt,
            'received_at' => $receivedAt,
        ];
    }

    public function forDevice(Device $device): static
    {
        return $this->state(fn () => [
            'device_id' => $device->id,
            'device_schema_version_id' => $device->device_schema_version_id,
        ]);
    }

    public function forTopic(SchemaVersionTopic $topic): static
    {
        return $this->state(fn () => [
            'schema_version_topic_id' => $topic->id,
            'device_schema_version_id' => $topic->device_schema_version_id,
        ]);
    }
}
