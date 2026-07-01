<?php

declare(strict_types=1);

use App\Domain\Alerts\Models\Alert;
use App\Domain\Alerts\Models\NotificationProfile;
use App\Domain\Alerts\Models\ThresholdPolicy;
use App\Domain\Automation\Jobs\StartAutomationRunFromTelemetry;
use App\Domain\Automation\Models\AutomationNotificationProfile;
use App\Domain\Automation\Models\AutomationTelemetryTrigger;
use App\Domain\Automation\Models\AutomationWorkflow;
use App\Domain\Automation\Models\AutomationWorkflowVersion;
use App\Domain\Automation\Services\ThresholdPolicyWorkflowProjector;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Enums\MetricUnit;
use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use App\Domain\Telemetry\Enums\ValidationStatus;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'cache.default' => 'array',
        'queue.default' => 'sync',
        'automation.step_log_mode' => 'all',
        'automation.capture_step_snapshots' => true,
        'services.sms.url' => 'https://dialog.example.test/sms',
        'services.sms.user' => 'Althinect_iot',
        'services.sms.digest' => 'digest-token',
        'services.sms.mask' => 'D IoT Alert',
        'services.sms.campaign_name' => 'alerts',
    ]);
});

it('dispatches one sms through a notification profile and skips repeats during the cooldown window', function (): void {
    Http::fake([
        'https://dialog.example.test/sms' => Http::response(['status' => 'accepted'], 200),
    ]);

    Cache::flush();

    $fixture = createThresholdPolicyExecutionFixture();
    $workflow = $fixture['workflow']->fresh(['activeVersion']);
    $firstTelemetryLog = createThresholdPolicyExecutionTelemetryLog($fixture, 9.5, Carbon::parse('2026-03-24 08:00:00'));

    (new StartAutomationRunFromTelemetry(
        workflowVersionId: $workflow->active_version_id,
        telemetryLogId: $firstTelemetryLog->id,
    ))->handle();

    $firstRun = $workflow->fresh()->runs()->latest('id')->first();
    $firstAlertStep = $firstRun?->steps()->where('node_id', 'alert-1')->latest('id')->first();

    expect($firstRun)->not->toBeNull()
        ->and($firstRun?->status->value)->toBe('completed')
        ->and($firstAlertStep?->status)->toBe('completed')
        ->and(data_get($firstAlertStep?->output_snapshot, 'dispatch.channel'))->toBe('sms')
        ->and(data_get($firstAlertStep?->output_snapshot, 'notification_profile_id'))->toBe($fixture['profile']->id)
        ->and(data_get($firstAlertStep?->output_snapshot, 'recipients'))->toBe(['+94781234567']);

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request): bool {
        return data_get($request->data(), 'messages.0.number') === '94781234567'
            && str_contains((string) data_get($request->data(), 'messages.0.text'), 'CLD 03 - 02')
            && str_contains((string) data_get($request->data(), 'messages.0.text'), '9.5')
            && str_contains((string) data_get($request->data(), 'messages.0.text'), 'Outside 2°C and 8°C');
    });

    $secondTelemetryLog = createThresholdPolicyExecutionTelemetryLog($fixture, 10.2, Carbon::parse('2026-03-24 08:05:00'));

    (new StartAutomationRunFromTelemetry(
        workflowVersionId: $workflow->active_version_id,
        telemetryLogId: $secondTelemetryLog->id,
    ))->handle();

    $secondRun = $workflow->fresh()->runs()->latest('id')->first();
    $secondAlertStep = $secondRun?->steps()->where('node_id', 'alert-1')->latest('id')->first();

    expect($secondRun)->not->toBeNull()
        ->and($secondRun?->status->value)->toBe('completed')
        ->and($secondAlertStep?->status)->toBe('skipped')
        ->and(data_get($secondAlertStep?->output_snapshot, 'reason'))->toBe('alert_cooldown_active');

    Http::assertSentCount(1);
});

it('stores one open alert for a managed threshold breach and skips repeat breached telemetry', function (): void {
    Http::fake([
        'https://dialog.example.test/sms' => Http::response(['status' => 'accepted'], 200),
    ]);

    $fixture = createManagedThresholdPolicyExecutionFixture();
    $workflow = $fixture['workflow']->fresh(['activeVersion']);
    $firstTelemetryLog = createThresholdPolicyExecutionTelemetryLog($fixture, 9.5, Carbon::parse('2026-03-24 08:00:00'));

    (new StartAutomationRunFromTelemetry(
        workflowVersionId: $workflow->active_version_id,
        telemetryLogId: $firstTelemetryLog->id,
    ))->handle();

    $firstAlert = Alert::query()
        ->where('threshold_policy_id', $fixture['policy']->id)
        ->whereNull('normalized_at')
        ->first();
    $firstRun = $workflow->fresh()->runs()->latest('id')->first();
    $firstAlertStep = $firstRun?->steps()->where('node_id', 'alert-1')->latest('id')->first();

    expect($firstAlert)->not->toBeNull()
        ->and($firstAlert?->alerted_at?->toIso8601String())->toBe($firstTelemetryLog->recorded_at->toIso8601String())
        ->and($firstAlert?->alerted_telemetry_log_id)->toBe($firstTelemetryLog->id)
        ->and($firstAlert?->alert_notification_sent_at)->not->toBeNull()
        ->and($firstRun?->status->value)->toBe('completed')
        ->and($firstAlertStep?->status)->toBe('completed')
        ->and(data_get($firstAlertStep?->output_snapshot, 'alert_id'))->toBe($firstAlert?->id);

    $secondTelemetryLog = createThresholdPolicyExecutionTelemetryLog($fixture, 10.2, Carbon::parse('2026-03-24 08:05:00'));

    (new StartAutomationRunFromTelemetry(
        workflowVersionId: $workflow->active_version_id,
        telemetryLogId: $secondTelemetryLog->id,
    ))->handle();

    $secondRun = $workflow->fresh()->runs()->latest('id')->first();
    $secondAlertStep = $secondRun?->steps()->where('node_id', 'alert-1')->latest('id')->first();
    $openAlerts = Alert::query()
        ->where('threshold_policy_id', $fixture['policy']->id)
        ->whereNull('normalized_at')
        ->get();

    expect($openAlerts)->toHaveCount(1)
        ->and($openAlerts->first()?->alerted_at?->toIso8601String())->toBe($firstTelemetryLog->recorded_at->toIso8601String())
        ->and($secondRun?->status->value)->toBe('completed')
        ->and($secondAlertStep?->status)->toBe('skipped')
        ->and(data_get($secondAlertStep?->output_snapshot, 'reason'))->toBe('threshold_alert_open');

    Http::assertSentCount(1);
});

it('normalizes a managed threshold alert once and opens a fresh row on the next breach', function (): void {
    Http::fake([
        'https://dialog.example.test/sms' => Http::response(['status' => 'accepted'], 200),
    ]);

    $fixture = createManagedThresholdPolicyExecutionFixture();
    $workflow = $fixture['workflow']->fresh(['activeVersion']);
    $breachTelemetryLog = createThresholdPolicyExecutionTelemetryLog($fixture, 9.5, Carbon::parse('2026-03-24 08:00:00'));

    (new StartAutomationRunFromTelemetry(
        workflowVersionId: $workflow->active_version_id,
        telemetryLogId: $breachTelemetryLog->id,
    ))->handle();

    $normalTelemetryLog = createThresholdPolicyExecutionTelemetryLog($fixture, 4.0, Carbon::parse('2026-03-24 08:10:00'));

    (new StartAutomationRunFromTelemetry(
        workflowVersionId: $workflow->active_version_id,
        telemetryLogId: $normalTelemetryLog->id,
    ))->handle();

    $normalizedAlert = Alert::query()
        ->where('threshold_policy_id', $fixture['policy']->id)
        ->orderBy('id')
        ->first();
    $normalizedRun = $workflow->fresh()->runs()->latest('id')->first();
    $normalizedConditionStep = $normalizedRun?->steps()->where('node_id', 'condition-1')->latest('id')->first();

    expect($normalizedAlert)->not->toBeNull()
        ->and($normalizedAlert?->normalized_at?->toIso8601String())->toBe($normalTelemetryLog->recorded_at->toIso8601String())
        ->and($normalizedAlert?->normalized_telemetry_log_id)->toBe($normalTelemetryLog->id)
        ->and($normalizedAlert?->normalized_notification_sent_at)->not->toBeNull()
        ->and(data_get($normalizedConditionStep?->output_snapshot, 'normalization.status'))->toBe('normalized')
        ->and(data_get($normalizedConditionStep?->output_snapshot, 'normalization.notification.status'))->toBe('sent');

    $secondBreachTelemetryLog = createThresholdPolicyExecutionTelemetryLog($fixture, 9.1, Carbon::parse('2026-03-24 08:20:00'));

    (new StartAutomationRunFromTelemetry(
        workflowVersionId: $workflow->active_version_id,
        telemetryLogId: $secondBreachTelemetryLog->id,
    ))->handle();

    $alerts = Alert::query()
        ->where('threshold_policy_id', $fixture['policy']->id)
        ->orderBy('id')
        ->get();

    expect($alerts)->toHaveCount(2)
        ->and($alerts->last()?->alerted_at?->toIso8601String())->toBe($secondBreachTelemetryLog->recorded_at->toIso8601String())
        ->and($alerts->last()?->normalized_at)->toBeNull();

    Http::assertSentCount(3);
    Http::assertSent(function (Request $request): bool {
        return str_contains((string) data_get($request->data(), 'messages.0.text'), 'returned to the normal range');
    });
});

/**
 * @return array{
 *     organization: Organization,
 *     device: Device,
 *     channel: DeviceChannel,
 *     parameter: ProfileParameterDefinition,
 *     workflow: AutomationWorkflow,
 *     profile: AutomationNotificationProfile
 * }
 */
function createThresholdPolicyExecutionFixture(): array
{
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
        'connection_state' => 'online',
        'last_seen_at' => now(),
    ]);
    $user = User::factory()->create([
        'phone_number' => '+94781234567',
    ]);
    $user->organizations()->syncWithoutDetaching([$organization->id]);

    $profile = AutomationNotificationProfile::factory()->sms()->create([
        'organization_id' => $organization->id,
        'name' => 'Cold Room SMS',
        'subject' => null,
        'body' => '{{ trigger.device_name }} temperature {{ trigger.value }} condition {{ alert.metadata.condition_label }}',
        'mask' => 'D IoT Alert',
        'campaign_name' => 'alerts',
        'recipients' => [],
    ]);
    $profile->users()->sync([$user->id]);

    $workflow = AutomationWorkflow::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Cold Room Automation',
    ]);

    $graph = [
        'version' => 1,
        'nodes' => [
            [
                'id' => 'trigger-1',
                'type' => 'telemetry-trigger',
                'data' => [
                    'config' => [
                        'mode' => 'event',
                        'source' => [
                            'device_id' => $device->id,
                            'device_channel_id' => $channel->id,
                            'parameter_key' => $parameter->key,
                        ],
                    ],
                ],
            ],
            [
                'id' => 'condition-1',
                'type' => 'condition',
                'data' => [
                    'config' => [
                        'mode' => 'guided',
                        'guided' => [
                            'left' => 'trigger.value',
                            'operator' => 'outside_between',
                            'right' => 2,
                            'right_secondary' => 8,
                        ],
                        'json_logic' => [
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
                        ],
                    ],
                ],
            ],
            [
                'id' => 'alert-1',
                'type' => 'alert',
                'data' => [
                    'config' => [
                        'notification_profile_id' => $profile->id,
                        'cooldown' => [
                            'value' => 24,
                            'unit' => 'hour',
                        ],
                        'metadata' => [
                            'condition_label' => 'Outside 2°C and 8°C',
                            'mask' => 'D IoT Alert',
                            'campaign_name' => 'alerts',
                        ],
                    ],
                ],
            ],
        ],
        'edges' => [
            ['id' => 'edge-1', 'source' => 'trigger-1', 'target' => 'condition-1'],
            ['id' => 'edge-2', 'source' => 'condition-1', 'target' => 'alert-1'],
        ],
        'viewport' => ['x' => 0, 'y' => 0, 'zoom' => 1],
    ];

    $version = AutomationWorkflowVersion::factory()->create([
        'automation_workflow_id' => $workflow->id,
        'version' => 1,
        'graph_json' => $graph,
    ]);

    $workflow->update([
        'active_version_id' => $version->id,
    ]);

    AutomationTelemetryTrigger::factory()->create([
        'organization_id' => $organization->id,
        'device_id' => $device->id,
        'workflow_version_id' => $version->id,
        'device_channel_id' => $channel->id,
        'channel_key' => $channel->key,
        'parameter_key' => $parameter->key,
    ]);

    return [
        'organization' => $organization,
        'device' => $device,
        'channel' => $channel,
        'parameter' => $parameter,
        'workflow' => $workflow,
        'profile' => $profile,
    ];
}

/**
 * @return array{
 *     organization: Organization,
 *     device: Device,
 *     channel: DeviceChannel,
 *     parameter: ProfileParameterDefinition,
 *     workflow: AutomationWorkflow,
 *     profile: NotificationProfile,
 *     policy: ThresholdPolicy
 * }
 */
function createManagedThresholdPolicyExecutionFixture(): array
{
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
        'connection_state' => 'online',
        'last_seen_at' => now(),
    ]);
    $user = User::factory()->create([
        'phone_number' => '+94781234567',
    ]);
    $user->organizations()->syncWithoutDetaching([$organization->id]);

    $profile = NotificationProfile::factory()->sms()->create([
        'organization_id' => $organization->id,
        'name' => 'Cold Room SMS',
        'subject' => null,
        'body' => '{{ trigger.device_name }} temperature {{ trigger.value }} condition {{ alert.metadata.condition_label }}',
        'mask' => 'D IoT Alert',
        'campaign_name' => 'alerts',
        'recipients' => [],
    ]);
    $profile->users()->sync([$user->id]);

    $policy = ThresholdPolicy::factory()->create([
        'organization_id' => $organization->id,
        'device_id' => $device->id,
        'device_channel_id' => $channel->id,
        'parameter_key' => $parameter->key,
        'notification_profile_id' => $profile->id,
        'name' => 'Cold Room Threshold Policy',
        'minimum_value' => 2,
        'maximum_value' => 8,
        'is_active' => true,
        'cooldown_value' => 24,
        'cooldown_unit' => 'hour',
    ]);

    $workflow = app(ThresholdPolicyWorkflowProjector::class)->sync($policy);

    expect($workflow)->toBeInstanceOf(AutomationWorkflow::class);

    return [
        'organization' => $organization,
        'device' => $device,
        'channel' => $channel,
        'parameter' => $parameter,
        'workflow' => $workflow,
        'profile' => $profile,
        'policy' => $policy,
    ];
}

function createThresholdPolicyExecutionTelemetryLog(array $fixture, float $value, Carbon $recordedAt): DeviceTelemetryLog
{
    return DeviceTelemetryLog::factory()
        ->forDevice($fixture['device'])
        ->forChannel($fixture['channel'])
        ->create([
            'transformed_values' => [
                'temperature' => $value,
            ],
            'raw_payload' => [
                'temperature' => $value,
            ],
            'recorded_at' => $recordedAt,
            'received_at' => $recordedAt,
            'validation_status' => ValidationStatus::Valid,
        ]);
}
