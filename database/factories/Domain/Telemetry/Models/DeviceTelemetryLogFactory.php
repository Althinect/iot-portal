<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Telemetry\Models;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
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
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $device = Device::factory()->create();
        $channel = DeviceChannel::factory()
            ->publish()
            ->create(['device_profile_version_id' => $device->device_profile_version_id]);

        $payload = ['value' => $this->faker->randomFloat(2, 0, 100)];

        return [
            'device_id' => $device->id,
            'device_profile_version_id' => $device->device_profile_version_id,
            'device_channel_id' => $channel->id,
            'validation_status' => ValidationStatus::Valid,
            'processing_state' => 'processed',
            'raw_payload' => $payload,
            'validation_errors' => null,
            'mutated_values' => null,
            'transformed_values' => $payload,
            'recorded_at' => now(),
            'received_at' => now(),
        ];
    }

    public function forDevice(Device $device): static
    {
        return $this->state(fn (array $attributes): array => [
            'device_id' => $device->id,
            'device_profile_version_id' => $device->device_profile_version_id,
        ]);
    }

    public function forChannel(DeviceChannel $channel): static
    {
        return $this->state(fn (array $attributes): array => [
            'device_profile_version_id' => $channel->device_profile_version_id,
            'device_channel_id' => $channel->id,
        ]);
    }

    public function forProfileVersion(DeviceProfileVersion $version): static
    {
        return $this->state(fn (array $attributes): array => [
            'device_profile_version_id' => $version->id,
        ]);
    }
}
