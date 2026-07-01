<?php

declare(strict_types=1);

use App\Domain\Automation\Enums\AutomationWorkflowStatus;
use App\Domain\Automation\Models\AutomationTelemetryTrigger;
use App\Domain\Automation\Models\AutomationWorkflow;
use App\Domain\Automation\Models\AutomationWorkflowVersion;
use App\Domain\Automation\Services\DatabaseTriggerMatcher;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createMatcherTelemetryLog(): DeviceTelemetryLog
{
    $profileVersion = DeviceProfileVersion::factory()->active()->mqtt()->create();

    $device = Device::factory()->create([
        'device_profile_version_id' => $profileVersion->id,
    ]);

    $channel = DeviceChannel::factory()->telemetry()->create([
        'device_profile_version_id' => $profileVersion->id,
        'key' => 'telemetry',
        'address' => 'telemetry',
    ]);

    return DeviceTelemetryLog::factory()
        ->forDevice($device)
        ->forChannel($channel)
        ->create([
            'transformed_values' => ['temperature' => 27.5],
            'raw_payload' => ['temperature' => 27.5],
        ]);
}

it('returns matching workflow version ids for telemetry context', function (): void {
    $telemetryLog = createMatcherTelemetryLog();

    $workflow = AutomationWorkflow::factory()->create([
        'organization_id' => $telemetryLog->device->organization_id,
        'status' => AutomationWorkflowStatus::Active,
    ]);

    $version = AutomationWorkflowVersion::factory()->create([
        'automation_workflow_id' => $workflow->id,
    ]);

    $workflow->update(['active_version_id' => $version->id]);

    AutomationTelemetryTrigger::factory()
        ->forDevice($telemetryLog->device)
        ->create([
            'organization_id' => $telemetryLog->device->organization_id,
            'workflow_version_id' => $version->id,
            'device_channel_id' => $telemetryLog->device_channel_id,
            'channel_key' => $telemetryLog->channel->key,
            'parameter_key' => 'temperature',
        ]);

    $matched = app(DatabaseTriggerMatcher::class)->matchTelemetryTriggers($telemetryLog->load('device', 'channel'));

    expect($matched->all())->toBe([$version->id]);
});

it('does not match paused workflows', function (): void {
    $telemetryLog = createMatcherTelemetryLog();

    $workflow = AutomationWorkflow::factory()->create([
        'organization_id' => $telemetryLog->device->organization_id,
        'status' => AutomationWorkflowStatus::Paused,
    ]);

    $version = AutomationWorkflowVersion::factory()->create([
        'automation_workflow_id' => $workflow->id,
    ]);

    $workflow->update(['active_version_id' => $version->id]);

    AutomationTelemetryTrigger::factory()
        ->forDevice($telemetryLog->device)
        ->create([
            'organization_id' => $telemetryLog->device->organization_id,
            'workflow_version_id' => $version->id,
            'device_channel_id' => $telemetryLog->device_channel_id,
            'channel_key' => $telemetryLog->channel->key,
            'parameter_key' => 'temperature',
        ]);

    $matched = app(DatabaseTriggerMatcher::class)->matchTelemetryTriggers($telemetryLog->load('device', 'channel'));

    expect($matched)->toBeEmpty();
});

it('invalidates cached trigger matches when workflow status changes', function (): void {
    $telemetryLog = createMatcherTelemetryLog();

    $workflow = AutomationWorkflow::factory()->create([
        'organization_id' => $telemetryLog->device->organization_id,
        'status' => AutomationWorkflowStatus::Active,
    ]);

    $version = AutomationWorkflowVersion::factory()->create([
        'automation_workflow_id' => $workflow->id,
    ]);

    $workflow->update(['active_version_id' => $version->id]);

    AutomationTelemetryTrigger::factory()
        ->forDevice($telemetryLog->device)
        ->create([
            'organization_id' => $telemetryLog->device->organization_id,
            'workflow_version_id' => $version->id,
            'device_channel_id' => $telemetryLog->device_channel_id,
            'channel_key' => $telemetryLog->channel->key,
            'parameter_key' => 'temperature',
        ]);

    $matchedBeforePause = app(DatabaseTriggerMatcher::class)->matchTelemetryTriggers($telemetryLog->load('device', 'channel'));
    expect($matchedBeforePause->all())->toBe([$version->id]);

    $workflow->update(['status' => AutomationWorkflowStatus::Paused]);

    $matchedAfterPause = app(DatabaseTriggerMatcher::class)->matchTelemetryTriggers($telemetryLog->load('device', 'channel'));
    expect($matchedAfterPause)->toBeEmpty();
});

it('can preflight whether telemetry has candidate triggers before queueing', function (): void {
    $telemetryLog = createMatcherTelemetryLog();

    $workflow = AutomationWorkflow::factory()->create([
        'organization_id' => $telemetryLog->device->organization_id,
        'status' => AutomationWorkflowStatus::Active,
    ]);

    $version = AutomationWorkflowVersion::factory()->create([
        'automation_workflow_id' => $workflow->id,
    ]);

    $workflow->update(['active_version_id' => $version->id]);

    AutomationTelemetryTrigger::factory()
        ->forDevice($telemetryLog->device)
        ->create([
            'organization_id' => $telemetryLog->device->organization_id,
            'workflow_version_id' => $version->id,
            'device_channel_id' => $telemetryLog->device_channel_id,
            'channel_key' => $telemetryLog->channel->key,
            'parameter_key' => 'temperature',
        ]);

    expect(app(DatabaseTriggerMatcher::class)->hasCandidateTelemetryTriggers($telemetryLog->load('device', 'channel')))->toBeTrue();
});

it('does not preflight telemetry with only mismatched channel triggers as candidates', function (): void {
    $telemetryLog = createMatcherTelemetryLog();
    $differentChannel = DeviceChannel::factory()->telemetry()->create([
        'device_profile_version_id' => $telemetryLog->device_profile_version_id,
        'key' => 'diagnostics',
        'address' => 'diagnostics',
    ]);

    $workflow = AutomationWorkflow::factory()->create([
        'organization_id' => $telemetryLog->device->organization_id,
        'status' => AutomationWorkflowStatus::Active,
    ]);

    $version = AutomationWorkflowVersion::factory()->create([
        'automation_workflow_id' => $workflow->id,
    ]);

    $workflow->update(['active_version_id' => $version->id]);

    AutomationTelemetryTrigger::factory()
        ->forDevice($telemetryLog->device)
        ->create([
            'organization_id' => $telemetryLog->device->organization_id,
            'workflow_version_id' => $version->id,
            'device_channel_id' => $differentChannel->id,
            'channel_key' => $differentChannel->key,
            'parameter_key' => 'temperature',
        ]);

    expect(app(DatabaseTriggerMatcher::class)->hasCandidateTelemetryTriggers($telemetryLog->load('device', 'channel')))->toBeFalse();
});
