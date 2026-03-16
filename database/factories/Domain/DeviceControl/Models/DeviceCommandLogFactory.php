<?php

declare(strict_types=1);

namespace Database\Factories\Domain\DeviceControl\Models;

use App\Domain\DeviceControl\Enums\CommandStatus;
use App\Domain\DeviceControl\Models\DeviceCommandLog;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Domain\Shared\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeviceCommandLog>
 */
class DeviceCommandLogFactory extends Factory
{
    protected $model = DeviceCommandLog::class;

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
            'response_schema_version_topic_id' => null,
            'user_id' => User::factory(),
            'command_payload' => [
                'brightness' => $this->faker->numberBetween(0, 100),
            ],
            'correlation_id' => $this->faker->optional()->uuid(),
            'status' => CommandStatus::Pending,
            'response_payload' => null,
            'error_message' => null,
            'sent_at' => null,
            'acknowledged_at' => null,
            'completed_at' => null,
        ];
    }

    public function sent(): static
    {
        return $this->state(fn () => [
            'status' => CommandStatus::Sent,
            'sent_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => CommandStatus::Completed,
            'sent_at' => now()->subMinutes(2),
            'acknowledged_at' => now()->subMinute(),
            'completed_at' => now(),
            'response_payload' => ['success' => true],
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => CommandStatus::Failed,
            'sent_at' => now()->subMinutes(5),
            'error_message' => 'Device not responding',
        ]);
    }
}
