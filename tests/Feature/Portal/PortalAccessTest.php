<?php

declare(strict_types=1);

use App\Domain\Authorization\Models\Role;
use App\Domain\Authorization\Permissions\RolePermission;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Permissions\DevicePermission;
use App\Domain\IoTDashboard\Models\IoTDashboard;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use App\Domain\Shared\Permissions\UserPermission;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use App\Domain\Telemetry\Permissions\DeviceTelemetryLogPermission;
use App\Filament\Portal\Resources\DeviceManagement\Devices\DeviceResource;
use App\Filament\Portal\Resources\IoTDashboards\IoTDashboardResource;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->organization = Organization::factory()->create(['slug' => 'portal-primary']);
    $this->otherOrganization = Organization::factory()->create(['slug' => 'portal-other']);
    $this->portalUser = User::factory()->create();
    $this->portalUser->organizations()->attach($this->organization);
    $this->otherPortalUser = User::factory()->create();
    $this->otherPortalUser->organizations()->attach($this->otherOrganization);
    $this->superAdmin = User::factory()->create(['is_super_admin' => true]);
    $this->userWithoutOrganization = User::factory()->create();

    foreach ([
        DevicePermission::VIEW_ANY->value,
        DevicePermission::VIEW->value,
        DeviceTelemetryLogPermission::VIEW_ANY->value,
        DeviceTelemetryLogPermission::VIEW->value,
        RolePermission::VIEW_ANY->value,
        UserPermission::VIEW_ANY->value,
    ] as $permissionName) {
        Permission::findOrCreate($permissionName, 'web');
    }

    foreach ([
        [$this->organization, $this->portalUser],
        [$this->otherOrganization, $this->otherPortalUser],
    ] as [$organization, $user]) {
        setPermissionsTeamId($organization->id);

        $role = Role::query()->create([
            'organization_id' => $organization->id,
            'name' => 'portal-viewer',
            'guard_name' => 'web',
        ]);
        $role->syncPermissions([
            DevicePermission::VIEW_ANY->value,
            DevicePermission::VIEW->value,
            DeviceTelemetryLogPermission::VIEW_ANY->value,
            DeviceTelemetryLogPermission::VIEW->value,
        ]);
        $user->assignRole($role);
    }
});

it('enforces the admin and portal panel access matrix', function (): void {
    $adminPanel = Filament::getPanel('admin');
    $portalPanel = Filament::getPanel('portal');

    expect($this->superAdmin->canAccessPanel($adminPanel))->toBeTrue()
        ->and($this->superAdmin->canAccessPanel($portalPanel))->toBeFalse()
        ->and($this->portalUser->canAccessPanel($adminPanel))->toBeFalse()
        ->and($this->portalUser->canAccessPanel($portalPanel))->toBeTrue()
        ->and($this->userWithoutOrganization->canAccessPanel($adminPanel))->toBeFalse()
        ->and($this->userWithoutOrganization->canAccessPanel($portalPanel))->toBeFalse();

    $this->get('/portal/login')->assertSuccessful();

    $this->actingAs($this->portalUser)
        ->get('/admin')
        ->assertForbidden();

    $this->get(route('filament.portal.pages.dashboard', ['tenant' => $this->organization]))
        ->assertSuccessful();

    $this->get(route('filament.portal.pages.dashboard', ['tenant' => $this->otherOrganization]))
        ->assertNotFound();
});

it('scopes portal devices and dashboards to the current tenant', function (): void {
    $device = Device::factory()->create(['organization_id' => $this->organization->id]);
    $otherDevice = Device::factory()->create(['organization_id' => $this->otherOrganization->id]);
    $dashboard = IoTDashboard::factory()->create(['organization_id' => $this->organization->id]);
    $otherDashboard = IoTDashboard::factory()->create(['organization_id' => $this->otherOrganization->id]);

    $this->actingAs($this->portalUser);
    setPermissionsTeamId($this->organization->id);
    Filament::setCurrentPanel('portal');
    Filament::setTenant($this->organization);
    Filament::bootCurrentPanel();

    expect(DeviceResource::getEloquentQuery()->pluck('devices.id')->all())->toBe([$device->id])
        ->and(IoTDashboardResource::getEloquentQuery()->pluck('iot_dashboards.id')->all())->toBe([$dashboard->id])
        ->and($this->portalUser->can('view', $device))->toBeTrue()
        ->and($this->portalUser->can('view', $otherDevice))->toBeFalse()
        ->and($this->portalUser->can('view', $dashboard))->toBeTrue()
        ->and($this->portalUser->can('view', $otherDashboard))->toBeFalse();

    $this->get(DeviceResource::getUrl('view', ['record' => $otherDevice], panel: 'portal', tenant: $this->organization))
        ->assertNotFound();

    $this->get(IoTDashboardResource::getUrl('view', ['record' => $otherDashboard], panel: 'portal', tenant: $this->organization))
        ->assertNotFound();
});

it('keeps portal dashboard and telemetry access read only and organization scoped', function (): void {
    $device = Device::factory()->create(['organization_id' => $this->organization->id]);
    $otherDevice = Device::factory()->create(['organization_id' => $this->otherOrganization->id]);
    $telemetryLog = DeviceTelemetryLog::factory()->create(['device_id' => $device->id]);
    $otherTelemetryLog = DeviceTelemetryLog::factory()->create(['device_id' => $otherDevice->id]);
    $dashboard = IoTDashboard::factory()->create(['organization_id' => $this->organization->id]);
    $widget = IoTDashboardWidget::factory()->create(['iot_dashboard_id' => $dashboard->id]);

    setPermissionsTeamId($this->organization->id);

    expect($this->portalUser->can('view', $telemetryLog))->toBeTrue()
        ->and($this->portalUser->can('view', $otherTelemetryLog))->toBeFalse()
        ->and($this->portalUser->can('create', IoTDashboard::class))->toBeFalse()
        ->and($this->portalUser->can('update', $dashboard))->toBeFalse()
        ->and($this->portalUser->can('delete', $dashboard))->toBeFalse()
        ->and($this->portalUser->can('create', IoTDashboardWidget::class))->toBeFalse()
        ->and($this->portalUser->can('update', $widget))->toBeFalse()
        ->and($this->portalUser->can('delete', $widget))->toBeFalse()
        ->and($this->superAdmin->can('create', IoTDashboard::class))->toBeTrue()
        ->and($this->superAdmin->can('update', $dashboard))->toBeTrue()
        ->and($this->superAdmin->can('delete', $dashboard))->toBeTrue()
        ->and($this->superAdmin->can('create', IoTDashboardWidget::class))->toBeTrue()
        ->and($this->superAdmin->can('update', $widget))->toBeTrue()
        ->and($this->superAdmin->can('delete', $widget))->toBeTrue();

    $this->actingAs($this->portalUser)
        ->postJson(route('admin.iot-dashboard.dashboards.widgets.layout', [
            'dashboard' => $dashboard,
            'widget' => $widget,
        ]), [
            'x' => 1,
            'y' => 1,
            'w' => 6,
            'h' => 4,
        ])
        ->assertForbidden();
});

it('authorizes portal snapshots against both tenant membership and dashboard ownership', function (): void {
    $dashboard = IoTDashboard::factory()->create(['organization_id' => $this->organization->id]);
    $otherDashboard = IoTDashboard::factory()->create(['organization_id' => $this->otherOrganization->id]);

    $this->actingAs($this->portalUser)
        ->getJson(route('portal.iot-dashboard.dashboards.snapshots', [
            'organization' => $this->organization,
            'dashboard' => $dashboard,
        ]))
        ->assertSuccessful()
        ->assertJsonPath('dashboard_id', $dashboard->id)
        ->assertJsonCount(0, 'widgets');

    $this->getJson(route('portal.iot-dashboard.dashboards.snapshots', [
        'organization' => $this->otherOrganization,
        'dashboard' => $otherDashboard,
    ]))->assertForbidden();

    $this->getJson(route('portal.iot-dashboard.dashboards.snapshots', [
        'organization' => $this->organization,
        'dashboard' => $otherDashboard,
    ]))->assertNotFound();
});
