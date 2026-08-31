<?php

declare(strict_types=1);

use App\Domain\Alerts\Models\Alert;
use App\Domain\Authorization\Enums\TenantRole;
use App\Domain\Authorization\Services\TenantRoleManager;
use App\Domain\Automation\Models\AutomationNotificationProfile;
use App\Domain\Automation\Models\AutomationThresholdPolicy;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Enums\ParameterCategory;
use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\DeviceProfile;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use App\Domain\Reporting\Models\ReportRun;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use App\Filament\Portal\Resources\Alerts\AlertResource;
use App\Filament\Portal\Resources\Alerts\Pages\ListAlerts;
use App\Filament\Portal\Resources\AutomationNotificationProfiles\AutomationNotificationProfileResource;
use App\Filament\Portal\Resources\AutomationNotificationProfiles\Pages\CreateAutomationNotificationProfile;
use App\Filament\Portal\Resources\AutomationThresholdPolicies\AutomationThresholdPolicyResource;
use App\Filament\Portal\Resources\AutomationThresholdPolicies\Pages\CreateAutomationThresholdPolicy;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->organization = Organization::factory()->create();
    $this->otherOrganization = Organization::factory()->create();
    $this->tenantAdmin = User::factory()->create();
    $this->tenantAdmin->organizations()->attach($this->organization);
    app(TenantRoleManager::class)->assign(
        $this->tenantAdmin,
        $this->organization,
        TenantRole::TenantAdmin,
    );

    $this->actingAs($this->tenantAdmin);
    Filament::setCurrentPanel('portal');
    Filament::setTenant($this->organization);
    Filament::bootCurrentPanel();
});

afterEach(function (): void {
    setPermissionsTeamId(null);
});

it('creates organization notification profiles with tenant-member recipients only', function (): void {
    $recipient = User::factory()->create(['email' => 'recipient@example.test']);
    $recipient->organizations()->attach($this->organization);
    $otherUser = User::factory()->create(['email' => 'other@example.test']);
    $otherUser->organizations()->attach($this->otherOrganization);
    $otherUser->organizations()->detach($this->organization);

    livewire(CreateAutomationNotificationProfile::class)
        ->fillForm([
            'name' => 'Operations Email',
            'channel' => 'email',
            'enabled' => true,
            'recipient_user_ids' => [$recipient->id],
            'subject' => 'Device alert',
            'body' => 'A device threshold was exceeded.',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $profile = AutomationNotificationProfile::query()->where('name', 'Operations Email')->sole();

    expect($profile->organization_id)->toBe($this->organization->id)
        ->and($profile->users()->pluck('users.id')->all())->toBe([$recipient->id])
        ->and(AutomationNotificationProfileResource::getEloquentQuery()->pluck('id')->all())->toBe([$profile->id])
        ->and(Filament::getTenant()?->getKey())->toBe($this->organization->id)
        ->and($otherUser->canAccessTenant($this->organization))->toBeFalse();

    livewire(CreateAutomationNotificationProfile::class)
        ->fillForm([
            'name' => 'Invalid Recipients',
            'channel' => 'email',
            'enabled' => true,
            'recipient_user_ids' => [$otherUser->id],
            'subject' => 'Invalid',
            'body' => 'Invalid recipient.',
        ])
        ->call('create');

    expect(AutomationNotificationProfile::query()->where('name', 'Invalid Recipients')->exists())->toBeFalse();
});

it('creates guided threshold policies only for current tenant device parameters', function (): void {
    $profile = DeviceProfile::withoutEvents(
        fn (): DeviceProfile => DeviceProfile::factory()->global()->create(),
    );
    $version = DeviceProfileVersion::factory()->active()->mqtt()->create([
        'device_profile_id' => $profile->id,
    ]);
    $channel = DeviceChannel::factory()->telemetry()->create([
        'device_profile_version_id' => $version->id,
    ]);
    $parameter = ProfileParameterDefinition::factory()->create([
        'device_channel_id' => $channel->id,
        'key' => 'temperature',
        'type' => ParameterDataType::Decimal,
        'category' => ParameterCategory::Measurement,
        'is_active' => true,
    ]);
    $device = Device::factory()->create([
        'organization_id' => $this->organization->id,
        'device_profile_version_id' => $version->id,
    ]);

    livewire(CreateAutomationThresholdPolicy::class)
        ->fillForm([
            'name' => 'High Temperature',
            'device_id' => $device->id,
            'parameter_key' => $parameter->id,
            'condition_mode' => 'guided',
            'guided_condition' => [
                'left' => 'trigger.value',
                'operator' => '>',
                'right' => 80,
            ],
            'is_active' => true,
            'cooldown_value' => 1,
            'cooldown_unit' => 'hour',
            'sort_order' => 0,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $policy = AutomationThresholdPolicy::query()->where('name', 'High Temperature')->sole();

    expect($policy->organization_id)->toBe($this->organization->id)
        ->and($policy->device_id)->toBe($device->id)
        ->and($policy->device_channel_id)->toBe($channel->id)
        ->and($policy->parameter_key)->toBe('temperature')
        ->and($policy->conditionLabel())->toContain('80')
        ->and(AutomationThresholdPolicyResource::getEloquentQuery()->pluck('id')->all())->toBe([$policy->id]);

    $otherDevice = Device::withoutEvents(
        fn (): Device => Device::factory()->create([
            'organization_id' => $this->otherOrganization->id,
            'device_profile_version_id' => $version->id,
        ]),
    );

    livewire(CreateAutomationThresholdPolicy::class)
        ->fillForm([
            'name' => 'Cross Tenant Threshold',
            'device_id' => $otherDevice->id,
            'parameter_key' => $parameter->id,
            'guided_condition' => [
                'left' => 'trigger.value',
                'operator' => '>',
                'right' => 90,
            ],
            'is_active' => true,
            'cooldown_value' => 1,
            'cooldown_unit' => 'hour',
            'sort_order' => 0,
        ])
        ->call('create')
        ->assertHasFormErrors(['device_id']);
});

it('allows operators to acknowledge tenant alerts and preserves the audit record', function (): void {
    $operator = User::factory()->create();
    $operator->organizations()->attach($this->organization);
    app(TenantRoleManager::class)->assign($operator, $this->organization, TenantRole::Operator);
    $alert = Alert::factory()->create([
        'organization_id' => $this->organization->id,
    ]);

    $this->actingAs($operator);
    Filament::setTenant($this->organization);

    livewire(ListAlerts::class)
        ->callTableAction('acknowledge', $alert, data: [
            'note' => 'Investigating with the plant team.',
        ])
        ->assertHasNoTableActionErrors();

    $alert->refresh();

    expect($alert->isAcknowledged())->toBeTrue()
        ->and($alert->acknowledged_by_user_id)->toBe($operator->id)
        ->and($alert->acknowledgement_note)->toBe('Investigating with the plant team.')
        ->and($operator->can('acknowledge', $alert))->toBeTrue();
});

it('keeps viewers read only and all operational records tenant scoped', function (): void {
    $viewer = User::factory()->create();
    $viewer->organizations()->attach($this->organization);
    app(TenantRoleManager::class)->assign($viewer, $this->organization, TenantRole::Viewer);
    $alert = Alert::factory()->create(['organization_id' => $this->organization->id]);
    $otherAlert = Alert::factory()->create(['organization_id' => $this->organization->id]);
    Alert::withoutEvents(function () use ($otherAlert): void {
        $otherAlert->forceFill(['organization_id' => $this->otherOrganization->id])->save();
    });
    $otherProfile = AutomationNotificationProfile::factory()->create([
        'organization_id' => $this->organization->id,
    ]);
    AutomationNotificationProfile::withoutEvents(function () use ($otherProfile): void {
        $otherProfile->forceFill(['organization_id' => $this->otherOrganization->id])->save();
    });

    $this->actingAs($viewer);
    Filament::setTenant($this->organization);

    expect($viewer->can('view', $alert))->toBeTrue()
        ->and($viewer->can('acknowledge', $alert))->toBeFalse()
        ->and($viewer->can('create', AutomationThresholdPolicy::class))->toBeFalse()
        ->and($viewer->can('create', AutomationNotificationProfile::class))->toBeFalse()
        ->and(AlertResource::getEloquentQuery()->pluck('id')->all())->toBe([$alert->id])
        ->and(AlertResource::getEloquentQuery()->whereKey($otherAlert->id)->exists())->toBeFalse()
        ->and(AutomationNotificationProfileResource::getEloquentQuery()->whereKey($otherProfile->id)->exists())->toBeFalse();
});

it('allows only tenant admins to manage report settings', function (): void {
    $operator = User::factory()->create();
    $operator->organizations()->attach($this->organization);
    app(TenantRoleManager::class)->assign($operator, $this->organization, TenantRole::Operator);

    expect($this->tenantAdmin->can('manageSettings', ReportRun::class))->toBeTrue()
        ->and($operator->can('manageSettings', ReportRun::class))->toBeFalse();
});
