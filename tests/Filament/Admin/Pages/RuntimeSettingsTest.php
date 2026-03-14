<?php

declare(strict_types=1);

use App\Domain\Authorization\Models\Role;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use App\Domain\Shared\Permissions\RuntimeSettingPermission;
use App\Domain\Shared\Services\RuntimeSettingManager;
use App\Domain\Shared\Services\RuntimeSettingRegistry;
use App\Filament\Admin\Pages\RuntimeSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'ingestion.enabled' => true,
        'ingestion.driver' => 'laravel',
        'ingestion.broadcast_realtime' => true,
        'ingestion.publish_analytics' => true,
        'automation.telemetry_fanout_enabled' => true,
        'iot.broadcast.raw_telemetry' => false,
        'horizon.auto_balancing.enabled' => false,
        'horizon.auto_balancing.supervisors.default.max_processes' => 3,
        'horizon.auto_balancing.supervisors.ingestion.max_processes' => 4,
        'horizon.auto_balancing.supervisors.side_effects.max_processes' => 4,
        'horizon.auto_balancing.supervisors.automation.max_processes' => 4,
        'horizon.auto_balancing.supervisors.simulations.max_processes' => 4,
    ]);
});

function assignRuntimeSettingPermissions(User $user, Organization $organization, array $permissions): void
{
    setPermissionsTeamId($organization->id);

    foreach (RuntimeSettingPermission::cases() as $permission) {
        Permission::findOrCreate($permission->value, 'web');
    }

    $role = Role::query()->create([
        'name' => fake()->unique()->word(),
        'guard_name' => 'web',
        'organization_id' => $organization->id,
    ]);

    $role->givePermissionTo($permissions);
    $organization->users()->syncWithoutDetaching([$user->id]);
    $user->assignRole($role);
}

it('forbids access without runtime settings permissions', function (): void {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();

    $organization->users()->syncWithoutDetaching([$user->id]);
    setPermissionsTeamId($organization->id);
    $this->actingAs($user);

    livewire(RuntimeSettings::class)->assertForbidden();
});

it('allows a user with view permission to inspect the runtime settings table and only see accessible organizations', function (): void {
    $organization = Organization::factory()->create(['name' => 'Alpha Org']);
    $otherOrganization = Organization::factory()->create(['name' => 'Beta Org']);
    $user = User::factory()->create();

    assignRuntimeSettingPermissions($user, $organization, [
        RuntimeSettingPermission::VIEW->value,
    ]);

    $this->actingAs($user);

    livewire(RuntimeSettings::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords(app(RuntimeSettingRegistry::class)->keys())
        ->assertSee('Alpha Org')
        ->assertDontSee('Beta Org')
        ->assertSee('Global Override')
        ->assertSee('Organization Override');
});

it('only allows global settings to be saved with the global update permission', function (): void {
    $organization = Organization::factory()->create();
    $viewer = User::factory()->create();
    $editor = User::factory()->create();

    assignRuntimeSettingPermissions($viewer, $organization, [
        RuntimeSettingPermission::VIEW->value,
    ]);

    assignRuntimeSettingPermissions($editor, $organization, [
        RuntimeSettingPermission::VIEW->value,
        RuntimeSettingPermission::UPDATE_GLOBAL->value,
    ]);

    setPermissionsTeamId($organization->id);
    $this->actingAs($viewer);

    livewire(RuntimeSettings::class)
        ->assertTableActionHidden('editGlobal', 'ingestion.pipeline.broadcast_realtime');

    $this->actingAs($editor);

    livewire(RuntimeSettings::class)
        ->assertTableActionVisible('editGlobal', 'ingestion.pipeline.broadcast_realtime')
        ->callTableAction('editGlobal', 'ingestion.pipeline.broadcast_realtime', data: [
            'value' => false,
        ])
        ->assertHasNoTableActionErrors();

    expect(app(RuntimeSettingManager::class)->booleanValue('ingestion.pipeline.broadcast_realtime'))->toBeFalse();
});

it('allows integer horizon scaling settings to be edited globally', function (): void {
    $organization = Organization::factory()->create();
    $editor = User::factory()->create();

    assignRuntimeSettingPermissions($editor, $organization, [
        RuntimeSettingPermission::VIEW->value,
        RuntimeSettingPermission::UPDATE_GLOBAL->value,
    ]);

    setPermissionsTeamId($organization->id);
    $this->actingAs($editor);

    livewire(RuntimeSettings::class)
        ->assertTableActionVisible('editGlobal', 'horizon.ingestion.max_processes')
        ->callTableAction('editGlobal', 'horizon.ingestion.max_processes', data: [
            'value' => 12,
        ])
        ->assertHasNoTableActionErrors();

    expect(app(RuntimeSettingManager::class)->intValue('horizon.ingestion.max_processes'))->toBe(12);
});

it('limits organization override actions to accessible organizations and supports inherit reset', function (): void {
    $organization = Organization::factory()->create(['name' => 'Alpha Org']);
    $otherOrganization = Organization::factory()->create(['name' => 'Beta Org']);
    $user = User::factory()->create();

    assignRuntimeSettingPermissions($user, $organization, [
        RuntimeSettingPermission::VIEW->value,
        RuntimeSettingPermission::UPDATE_ORGANIZATION->value,
    ]);

    setPermissionsTeamId($organization->id);
    $this->actingAs($user);

    $component = livewire(RuntimeSettings::class);

    $component
        ->call('selectOrganization', $otherOrganization->id)
        ->assertSet('selectedOrganizationId', null)
        ->assertTableActionHidden('editOrganization', 'ingestion.pipeline.publish_analytics');

    $component
        ->call('selectOrganization', $organization->id)
        ->assertSet('selectedOrganizationId', $organization->id)
        ->assertTableActionVisible('editOrganization', 'ingestion.pipeline.publish_analytics')
        ->callTableAction('editOrganization', 'ingestion.pipeline.publish_analytics', data: [
            'value' => false,
        ])
        ->assertHasNoTableActionErrors();

    expect(app(RuntimeSettingManager::class)->booleanValue('ingestion.pipeline.publish_analytics', $organization->id))->toBeFalse();

    $component
        ->callTableAction('resetOrganization', 'ingestion.pipeline.publish_analytics')
        ->assertSuccessful();

    expect(app(RuntimeSettingManager::class)->resolvedSetting('ingestion.pipeline.publish_analytics', $organization->id)['source'])
        ->toBe('default');
});
