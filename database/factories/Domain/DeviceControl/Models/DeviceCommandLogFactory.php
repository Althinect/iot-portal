<?php

declare(strict_types=1);

namespace Database\Factories\Domain\DeviceControl\Models;

use App\Domain\DeviceControl\Enums\CommandStatus;
use App\Domain\DeviceControl\Models\DeviceCommandLog;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\Shared\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeviceCommandLog>
 */
class DeviceCommandLogFactory extends Factory
{
    protected $model = DeviceCommandLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $device = Device::factory()->create();
        $commandChannel = DeviceChannel::factory()
            ->command()
            ->create(['device_profile_version_id' => $device->device_profile_version_id]);

        $responseChannel = DeviceChannel::factory()
            ->publish()
            ->create(['device_profile_version_id' => $device->device_profile_version_id]);

        return [
            'device_id' => $device->id,
            'device_channel_id' => $commandChannel->id,
            'response_device_channel_id' => $responseChannel->id,
            'user_id' => User::factory(),
            'command_payload' => ['power' => true],
            'response_payload' => null,
            'correlation_id' => $this->faker->uuid(),
            'status' => CommandStatus::Pending,
            'sent_at' => null,
            'acknowledged_at' => null,
            'completed_at' => null,
        ];
    }

    public function sent(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CommandStatus::Sent,
            'sent_at' => now(),
        ]);
    }

    public function acknowledged(): static
    {
        return $this->sent()->state(fn (array $attributes): array => [
            'status' => CommandStatus::Acknowledged,
            'acknowledged_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->acknowledged()->state(fn (array $attributes): array => [
            'status' => CommandStatus::Completed,
            'response_payload' => ['accepted' => true],
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->sent()->state(fn (array $attributes): array => [
            'status' => CommandStatus::Failed,
            'error_message' => 'Command failed.',
            'completed_at' => now(),
        ]);
    }
}
