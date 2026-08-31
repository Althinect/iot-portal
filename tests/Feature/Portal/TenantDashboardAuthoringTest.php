<?php

declare(strict_types=1);

use App\Domain\Authorization\Enums\TenantRole;
use App\Domain\Authorization\Services\TenantRoleManager;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Enums\ParameterCategory;
use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\DeviceProfile;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use App\Domain\IoTDashboard\Models\IoTDashboard;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use App\Domain\Shared\Models\Entity;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use App\Filament\Portal\Pages\IoTDashboard as IoTDashboardPage;
use App\Filament\Portal\Resources\IoTDashboards\IoTDashboardResource;
use App\Filament\Portal\Resources\IoTDashboards\Pages\CreateIoTDashboard;
use App\Filament\Portal\Resources\IoTDashboards\Pages\EditIoTDashboard;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->organization = Organization::factory()->create();
    $this->otherOrganization = Organization::factory()->create();
    $this->operator = User::factory()->create();
    $this->operator->organizations()->attach($this->organization);
    app(TenantRoleManager::class)->assign(
        $this->operator,
        $this->organization,
        TenantRole::Operator,
    );

    $this->actingAs($this->operator);
    Filament::setCurrentPanel('portal');
    Filament::setTenant($this->organization);
    Filament::bootCurrentPanel();
});

afterEach(function (): void {
    setPermissionsTeamId(null);
});

it('creates and updates dashboards inside the active tenant with an optional primary site', function (): void {
    $site = Entity::factory()->create([
        'organization_id' => $this->organization->id,
    ]);

    livewire(CreateIoTDashboard::class)
        ->fillForm([
            'name' => 'Plant Operations',
            'slug' => 'plant-operations',
            'entity_id' => $site->id,
            'refresh_interval_seconds' => 15,
            'default_history_preset' => '6h',
            'is_active' => true,
            'description' => 'Shared operations dashboard.',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $dashboard = IoTDashboard::query()->where('slug', 'plant-operations')->sole();

    expect($dashboard->organization_id)->toBe($this->organization->id)
        ->and($dashboard->entity_id)->toBe($site->id)
        ->and($this->operator->can('create', IoTDashboard::class))->toBeTrue()
        ->and($this->operator->can('update', $dashboard))->toBeTrue();

    livewire(EditIoTDashboard::class, ['record' => $dashboard->id])
        ->fillForm(['name' => 'Plant Operations Updated'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($dashboard->fresh()->name)->toBe('Plant Operations Updated');
});

it('rejects cross-tenant sites and dashboard routes', function (): void {
    $otherSite = Entity::withoutEvents(
        fn (): Entity => Entity::factory()->create([
            'organization_id' => $this->otherOrganization->id,
            'uuid' => (string) Str::uuid(),
            'label' => 'Other Site',
        ]),
    );
    $otherDashboard = IoTDashboard::withoutEvents(
        fn (): IoTDashboard => IoTDashboard::factory()->create([
            'organization_id' => $this->otherOrganization->id,
        ]),
    );

    livewire(CreateIoTDashboard::class)
        ->fillForm([
            'name' => 'Invalid Dashboard',
            'slug' => 'invalid-dashboard',
            'entity_id' => $otherSite->id,
            'refresh_interval_seconds' => 10,
            'default_history_preset' => '6h',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasFormErrors(['entity_id']);

    expect(IoTDashboardResource::getEloquentQuery()->whereKey($otherDashboard->id)->exists())->toBeFalse();

    $this->get(IoTDashboardResource::getUrl('edit', ['record' => $otherDashboard]))
        ->assertNotFound();
});

it('archives and restores dashboards without hard deletion', function (): void {
    $dashboard = IoTDashboard::factory()->create([
        'organization_id' => $this->organization->id,
    ]);

    livewire(EditIoTDashboard::class, ['record' => $dashboard->id])
        ->callAction('delete')
        ->assertHasNoActionErrors();

    expect(IoTDashboard::query()->whereKey($dashboard->id)->exists())->toBeFalse()
        ->and(IoTDashboard::withTrashed()->whereKey($dashboard->id)->exists())->toBeTrue();

    livewire(EditIoTDashboard::class, ['record' => $dashboard->id])
        ->callAction('restore')
        ->assertHasNoActionErrors();

    expect($dashboard->fresh())->not->toBeNull();
});

it('lets operators create widgets and persist layout only within their tenant', function (): void {
    $dashboard = IoTDashboard::factory()->create([
        'organization_id' => $this->organization->id,
    ]);
    $profile = DeviceProfile::withoutEvents(
        fn (): DeviceProfile => DeviceProfile::factory()->global()->create(),
    );
    $version = DeviceProfileVersion::factory()->active()->mqtt()->create([
        'device_profile_id' => $profile->id,
    ]);
    $channel = DeviceChannel::factory()->telemetry()->create([
        'device_profile_version_id' => $version->id,
    ]);
    ProfileParameterDefinition::factory()->create([
        'device_channel_id' => $channel->id,
        'key' => 'temperature',
        'label' => 'Temperature',
        'type' => ParameterDataType::Decimal,
        'category' => ParameterCategory::Measurement,
        'is_active' => true,
    ]);
    $device = Device::factory()->create([
        'organization_id' => $this->organization->id,
        'device_profile_version_id' => $version->id,
    ]);

    livewire(IoTDashboardPage::class)
        ->set('dashboardId', $dashboard->id)
        ->callAction('addLineWidget', data: [
            'title' => 'Temperature Trend',
            'device_id' => $device->id,
            'device_channel_id' => $channel->id,
            'parameter_keys' => ['temperature'],
            'use_websocket' => false,
            'use_polling' => true,
            'polling_interval_seconds' => 10,
            'lookback_minutes' => 60,
            'max_points' => 240,
            'grid_columns' => '6',
            'card_height_px' => 384,
        ])
        ->assertHasNoActionErrors();

    $widget = IoTDashboardWidget::query()->sole();

    expect($widget->iot_dashboard_id)->toBe($dashboard->id)
        ->and($widget->device_id)->toBe($device->id)
        ->and($widget->device_channel_id)->toBe($channel->id)
        ->and($this->operator->can('layout', $widget))->toBeTrue();

    $this->postJson(route('portal.iot-dashboard.dashboards.widgets.layout', [
        'organization' => $this->organization,
        'dashboard' => $dashboard,
        'widget' => $widget,
    ]), [
        'x' => 4,
        'y' => 2,
        'w' => 8,
        'h' => 5,
    ])->assertSuccessful();

    expect($widget->fresh()->layoutArray())
        ->toMatchArray(['x' => 4, 'y' => 2, 'w' => 8, 'h' => 5]);
});

it('keeps viewers read only for dashboards widgets and layout persistence', function (): void {
    $dashboard = IoTDashboard::factory()->create([
        'organization_id' => $this->organization->id,
    ]);
    $widget = IoTDashboardWidget::factory()->create([
        'iot_dashboard_id' => $dashboard->id,
    ]);
    $viewer = User::factory()->create();
    $viewer->organizations()->attach($this->organization);
    app(TenantRoleManager::class)->assign($viewer, $this->organization, TenantRole::Viewer);

    $this->actingAs($viewer);
    Filament::setTenant($this->organization);

    expect($viewer->can('view', $dashboard))->toBeTrue()
        ->and($viewer->can('create', IoTDashboard::class))->toBeFalse()
        ->and($viewer->can('update', $dashboard))->toBeFalse()
        ->and($viewer->can('create', IoTDashboardWidget::class))->toBeFalse()
        ->and($viewer->can('layout', $widget))->toBeFalse();

    $page = livewire(IoTDashboardPage::class)->set('dashboardId', $dashboard->id);
    $payload = $page->instance()->getWidgetBootstrapPayloadProperty();

    expect($page->instance()->canManageWidgets())->toBeFalse()
        ->and($payload[0]['read_only'])->toBeTrue()
        ->and($payload[0])->not->toHaveKey('layout_url');

    $this->postJson(route('portal.iot-dashboard.dashboards.widgets.layout', [
        'organization' => $this->organization,
        'dashboard' => $dashboard,
        'widget' => $widget,
    ]), [
        'x' => 1,
        'y' => 1,
        'w' => 6,
        'h' => 4,
    ])->assertForbidden();
});
