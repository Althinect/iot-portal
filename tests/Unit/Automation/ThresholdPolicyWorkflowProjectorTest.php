<?php

declare(strict_types=1);

use App\Domain\Automation\Enums\AutomationWorkflowStatus;
use App\Domain\Automation\Models\AutomationNotificationProfile;
use App\Domain\Automation\Models\AutomationTelemetryTrigger;
use App\Domain\Automation\Models\AutomationThresholdPolicy;
use App\Domain\Automation\Services\ThresholdPolicyWorkflowProjector;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Enums\MetricUnit;
use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('projects a managed threshold-policy workflow with a profile-backed alert node', function (): void {
    $organization = Organization::factory()->create();
    $profileVersion = DeviceProfileVersion::factory()->active()->mqtt()->create();
    $channel = DeviceChannel::factory()->telemetry()->create([
        'device_profile_version_id' => $profileVersion->id,
        'key' => 'telemetry',
        'label' => 'Telemetry',
        'address' => 'telemetry',
    ]);
    $parameter = ProfileParameterDefinition::factory()->create([
        'device_channel_id' => $channel->id,
        'key' => 'temperature',
        'label' => 'Temperature',
        'json_path' => '$.temperature',
        'type' => ParameterDataType::Decimal,
        'unit' => MetricUnit::Celsius->value,
        'required' => true,
        'is_active' => true,
    ]);
    $device = Device::factory()->create([
        'organization_id' => $organization->id,
        'device_profile_version_id' => $profileVersion->id,
        'name' => 'CLD 03 - 02',
    ]);
    $user = User::factory()->create([
        'phone_number' => '+94781234567',
    ]);
    $user->organizations()->syncWithoutDetaching([$organization->id]);

    $profile = AutomationNotificationProfile::factory()->sms()->create([
        'organization_id' => $organization->id,
        'name' => 'Cold Room SMS',
        'body' => '{{ trigger.device_name }} {{ trigger.value }} {{ alert.metadata.condition_label }}',
        'mask' => 'D IoT Alert',
        'campaign_name' => 'alerts',
        'recipients' => [],
    ]);
    $profile->users()->sync([$user->id]);

    $policy = AutomationThresholdPolicy::factory()->create([
        'organization_id' => $organization->id,
        'device_id' => $device->id,
        'device_channel_id' => $channel->id,
        'parameter_key' => $parameter->key,
        'notification_profile_id' => $profile->id,
        'name' => 'CLD 03 - 02 Temperature Threshold',
        'minimum_value' => 2,
        'maximum_value' => 8,
        'cooldown_value' => 24,
        'cooldown_unit' => 'hour',
        'sort_order' => 1,
    ]);

    $workflow = app(ThresholdPolicyWorkflowProjector::class)->sync($policy);

    expect($workflow)->not->toBeNull()
        ->and($workflow?->organization_id)->toBe($organization->id)
        ->and($workflow?->status)->toBe(AutomationWorkflowStatus::Active)
        ->and($workflow?->is_managed)->toBeTrue()
        ->and($workflow?->managed_type)->toBe(ThresholdPolicyWorkflowProjector::MANAGED_TYPE)
        ->and(data_get($workflow?->managed_metadata, 'threshold_policy_id'))->toBe($policy->id)
        ->and(data_get($workflow?->managed_metadata, 'notification_profile_id'))->toBe($profile->id)
        ->and($policy->fresh()?->managed_workflow_id)->toBe($workflow?->id)
        ->and($workflow?->activeVersion)->not->toBeNull();

    $graph = $workflow?->activeVersion?->graph_json;
    $nodes = collect(data_get($graph, 'nodes', []))->keyBy('id');

    expect($nodes->keys()->all())->toBe(['trigger-1', 'condition-1', 'alert-1'])
        ->and(data_get($nodes->get('trigger-1'), 'data.config.source'))->toMatchArray([
            'device_id' => $device->id,
            'device_channel_id' => $channel->id,
            'parameter_key' => $parameter->key,
        ])
        ->and(data_get($nodes->get('condition-1'), 'data.config.mode'))->toBe('guided')
        ->and(data_get($nodes->get('condition-1'), 'data.config.guided'))->toMatchArray([
            'left' => 'trigger.value',
            'operator' => 'outside_between',
            'right' => 2.0,
            'right_secondary' => 8.0,
        ])
        ->and(data_get($nodes->get('condition-1'), 'data.config.json_logic'))->toMatchArray([
            'or' => [
                [
                    '<' => [
                        ['var' => 'trigger.value'],
                        2,
                    ],
                ],
                [
                    '>' => [
                        ['var' => 'trigger.value'],
                        8,
                    ],
                ],
            ],
        ])
        ->and(data_get($nodes->get('alert-1'), 'data.config.notification_profile_id'))->toBe($profile->id)
        ->and(data_get($nodes->get('alert-1'), 'data.config.cooldown'))->toMatchArray([
            'value' => 24,
            'unit' => 'hour',
        ])
        ->and(data_get($nodes->get('alert-1'), 'data.config.metadata'))->toMatchArray([
            'notification_profile_id' => $profile->id,
            'threshold_policy_id' => $policy->id,
            'condition_label' => 'Outside 2°C and 8°C',
            'mask' => 'D IoT Alert',
            'campaign_name' => 'alerts',
        ])
        ->and(data_get($nodes->get('alert-1'), 'data.summary'))->toContain('1 user(s)')
        ->and(data_get($nodes->get('alert-1'), 'data.summary'))->toContain('24 hour(s)');

    expect(AutomationTelemetryTrigger::query()
        ->where('workflow_version_id', $workflow?->active_version_id)
        ->where('device_id', $device->id)
        ->where('device_channel_id', $channel->id)
        ->where('parameter_key', $parameter->key)
        ->exists())->toBeTrue();
});
