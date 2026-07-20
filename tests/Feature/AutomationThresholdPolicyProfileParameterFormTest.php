<?php

declare(strict_types=1);

use App\Domain\Automation\Models\AutomationThresholdPolicy;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use App\Domain\Shared\Models\Organization;
use App\Filament\Admin\Resources\AutomationThresholdPolicies\AutomationThresholdPolicyResource;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('fills an existing threshold policy with its profile parameter definition id', function (): void {
    $fixture = createThresholdPolicyProfileParameterFixture();

    $formData = AutomationThresholdPolicyResource::prepareThresholdPolicyFormDataBeforeFill(
        $fixture['policy']->attributesToArray(),
        $fixture['policy'],
    );

    expect($fixture['policy']->profileParameterDefinition()?->is($fixture['parameter']))->toBeTrue()
        ->and($formData['parameter_key'])->toBe((string) $fixture['parameter']->id);
});

it('stores selected profile parameter definitions as policy channel and key values', function (): void {
    $fixture = createThresholdPolicyProfileParameterFixture();

    $preparedData = AutomationThresholdPolicyResource::prepareThresholdPolicyFormData([
        'organization_id' => $fixture['organization']->id,
        'device_id' => $fixture['device']->id,
        'parameter_key' => $fixture['secondary_parameter']->id,
        'name' => 'Updated freezer threshold',
        'is_active' => true,
        'condition_mode' => 'guided',
        'guided_condition' => [
            'operator' => 'outside_between',
            'right' => -20,
            'right_secondary' => -5,
        ],
        'cooldown_value' => 1,
        'cooldown_unit' => 'day',
        'notification_profile_id' => null,
        'sort_order' => 1,
    ], (int) $fixture['policy']->id);

    $fixture['policy']->fill($preparedData)->save();
    $fixture['policy']->refresh();

    expect($preparedData['device_channel_id'])->toBe($fixture['secondary_channel']->id)
        ->and($preparedData['parameter_key'])->toBe('temperature_2')
        ->and($fixture['policy']->device_channel_id)->toBe($fixture['secondary_channel']->id)
        ->and($fixture['policy']->parameter_key)->toBe('temperature_2')
        ->and($fixture['policy']->profileParameterDefinition()?->is($fixture['secondary_parameter']))->toBeTrue();
});

/**
 * @return array{
 *     organization: Organization,
 *     device: Device,
 *     channel: DeviceChannel,
 *     parameter: ProfileParameterDefinition,
 *     secondary_channel: DeviceChannel,
 *     secondary_parameter: ProfileParameterDefinition,
 *     policy: AutomationThresholdPolicy
 * }
 */
function createThresholdPolicyProfileParameterFixture(): array
{
    $organization = Organization::factory()->create();
    $profileVersion = DeviceProfileVersion::factory()->active()->create();
    $device = Device::factory()->create([
        'organization_id' => $organization->id,
        'device_profile_version_id' => $profileVersion->id,
    ]);
    $channel = DeviceChannel::factory()
        ->publish()
        ->create(['device_profile_version_id' => $profileVersion->id]);
    $parameter = ProfileParameterDefinition::factory()->create([
        'device_channel_id' => $channel->id,
        'key' => 'temperature',
        'label' => 'Temperature',
        'is_active' => true,
    ]);
    $secondaryChannel = DeviceChannel::factory()
        ->publish()
        ->create(['device_profile_version_id' => $profileVersion->id]);
    $secondaryParameter = ProfileParameterDefinition::factory()->create([
        'device_channel_id' => $secondaryChannel->id,
        'key' => 'temperature_2',
        'label' => 'Secondary Temperature',
        'is_active' => true,
    ]);
    $policy = AutomationThresholdPolicy::factory()
        ->withoutNotificationProfile()
        ->create([
            'organization_id' => $organization->id,
            'device_id' => $device->id,
            'device_channel_id' => $channel->id,
            'parameter_key' => $parameter->key,
            'name' => 'Freezer threshold',
            'is_active' => true,
        ]);

    return [
        'organization' => $organization,
        'device' => $device,
        'channel' => $channel,
        'parameter' => $parameter,
        'secondary_channel' => $secondaryChannel,
        'secondary_parameter' => $secondaryParameter,
        'policy' => $policy,
    ];
}
