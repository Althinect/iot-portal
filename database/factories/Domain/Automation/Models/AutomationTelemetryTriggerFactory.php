<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Automation\Models;

use App\Domain\Automation\Models\AutomationTelemetryTrigger;
use App\Domain\Automation\Models\AutomationWorkflowVersion;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\Shared\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AutomationTelemetryTrigger>
 */
class AutomationTelemetryTriggerFactory extends Factory
{
    protected $model = AutomationTelemetryTrigger::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'workflow_version_id' => AutomationWorkflowVersion::factory(),
            'device_id' => null,
            'device_channel_id' => null,
            'channel_key' => null,
            'parameter_key' => null,
            'filter_expression' => null,
        ];
    }

    public function forDevice(Device $device): static
    {
        return $this->state(fn (): array => [
            'organization_id' => $device->organization_id,
            'device_id' => $device->id,
        ]);
    }

    public function forChannel(DeviceChannel $channel): static
    {
        return $this->state(fn (): array => [
            'device_channel_id' => $channel->id,
            'channel_key' => $channel->key,
        ]);
    }

    public function forParameter(string $parameterKey): static
    {
        return $this->state(fn (): array => [
            'parameter_key' => $parameterKey,
        ]);
    }
}
