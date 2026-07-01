<?php

declare(strict_types=1);

namespace Database\Factories\Domain\DataIngestion\Models;

use App\Domain\DataIngestion\Enums\IngestionStatus;
use App\Domain\DataIngestion\Models\IngestionMessage;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IngestionMessage>
 */
class IngestionMessageFactory extends Factory
{
    protected $model = IngestionMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $device = Device::factory()->create();
        $channel = DeviceChannel::factory()
            ->publish()
            ->create(['device_profile_version_id' => $device->device_profile_version_id]);

        return [
            'organization_id' => $device->organization_id,
            'device_id' => $device->id,
            'device_profile_version_id' => $device->device_profile_version_id,
            'device_channel_id' => $channel->id,
            'source_subject' => $channel->address,
            'source_protocol' => 'mqtt',
            'source_message_id' => (string) Str::uuid(),
            'source_deduplication_key' => hash('sha256', (string) Str::uuid()),
            'raw_payload' => ['value' => $this->faker->randomFloat(2, 0, 100)],
            'error_summary' => null,
            'status' => IngestionStatus::Completed,
            'received_at' => now(),
            'processed_at' => now(),
        ];
    }
}
