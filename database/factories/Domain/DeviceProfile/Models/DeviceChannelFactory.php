<?php

declare(strict_types=1);

namespace Database\Factories\Domain\DeviceProfile\Models;

use App\Domain\DeviceProfile\Enums\ChannelDirection;
use App\Domain\DeviceProfile\Enums\ChannelPurpose;
use App\Domain\DeviceProfile\Enums\ChannelTransport;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DeviceChannel>
 */
class DeviceChannelFactory extends Factory
{
    protected $model = DeviceChannel::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $key = 'channel_'.bin2hex(random_bytes(2));
        $direction = random_int(0, 1) === 0 ? ChannelDirection::Publish : ChannelDirection::Subscribe;
        $publishPurposes = [ChannelPurpose::State, ChannelPurpose::Telemetry, ChannelPurpose::Ack];

        return [
            'device_profile_version_id' => DeviceProfileVersion::factory(),
            'key' => $key,
            'label' => Str::title(str_replace('_', ' ', $key)),
            'direction' => $direction,
            'purpose' => $direction === ChannelDirection::Subscribe
                ? ChannelPurpose::Command
                : $publishPurposes[array_rand($publishPurposes)],
            'transport' => ChannelTransport::Mqtt,
            'address' => $key,
            'http_method' => '',
            'description' => null,
            'qos' => random_int(0, 2),
            'retain' => random_int(1, 100) <= 20,
            'sequence' => random_int(0, 10),
            'options' => null,
        ];
    }

    public function publish(): static
    {
        return $this->state(fn () => [
            'direction' => ChannelDirection::Publish,
            'purpose' => ChannelPurpose::Telemetry,
        ]);
    }

    public function subscribe(): static
    {
        return $this->state(fn () => [
            'direction' => ChannelDirection::Subscribe,
            'purpose' => ChannelPurpose::Command,
        ]);
    }

    public function command(): static
    {
        return $this->subscribe()->state(fn () => [
            'key' => 'command',
            'label' => 'Command',
            'address' => 'command',
        ]);
    }

    public function telemetry(): static
    {
        return $this->state(fn () => [
            'key' => 'telemetry',
            'direction' => ChannelDirection::Publish,
            'purpose' => ChannelPurpose::Telemetry,
            'address' => 'telemetry',
        ]);
    }

    public function stateChannel(): static
    {
        return $this->state(fn () => [
            'key' => 'state',
            'direction' => ChannelDirection::Publish,
            'purpose' => ChannelPurpose::State,
            'address' => 'state',
            'retain' => true,
        ]);
    }

    public function ack(): static
    {
        return $this->state(fn () => [
            'key' => 'ack',
            'direction' => ChannelDirection::Publish,
            'purpose' => ChannelPurpose::Ack,
            'address' => 'ack',
        ]);
    }

    public function mqtt(): static
    {
        return $this->state(fn () => [
            'transport' => ChannelTransport::Mqtt,
        ]);
    }

    public function http(): static
    {
        return $this->state(fn () => [
            'transport' => ChannelTransport::Http,
            'http_method' => 'POST',
        ]);
    }
}
