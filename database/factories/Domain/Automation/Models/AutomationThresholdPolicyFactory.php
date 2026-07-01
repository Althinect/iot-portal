<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Automation\Models;

use App\Domain\Alerts\Models\NotificationProfile;
use App\Domain\Alerts\Models\ThresholdPolicy;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use App\Domain\Shared\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ThresholdPolicy>
 */
class AutomationThresholdPolicyFactory extends Factory
{
    protected $model = ThresholdPolicy::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $device = Device::factory()->create();
        $channel = DeviceChannel::factory()
            ->publish()
            ->create(['device_profile_version_id' => $device->device_profile_version_id]);

        $parameter = ProfileParameterDefinition::factory()
            ->create(['device_channel_id' => $channel->id]);

        return [
            'organization_id' => $device->organization_id,
            'device_id' => $device->id,
            'device_channel_id' => $channel->id,
            'parameter_key' => $parameter->key,
            'name' => $this->faker->words(3, true),
            'minimum_value' => null,
            'maximum_value' => $this->faker->randomFloat(3, 50, 100),
            'is_active' => true,
            'cooldown_value' => 1,
            'cooldown_unit' => 'day',
            'notification_profile_id' => NotificationProfile::factory()->state([
                'organization_id' => Organization::query()->find($device->organization_id)?->id ?? $device->organization_id,
            ]),
            'sort_order' => 0,
            'managed_workflow_id' => null,
            'legacy_alert_rule_id' => null,
            'legacy_metadata' => null,
        ];
    }

    public function withoutNotificationProfile(): static
    {
        return $this->state(fn (array $attributes): array => [
            'notification_profile_id' => null,
        ]);
    }
}
