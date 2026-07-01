<?php

declare(strict_types=1);

use App\Domain\DeviceProfile\Models\DeviceProfile;
use App\Domain\DeviceProfile\Permissions\DeviceProfilePermission;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $organization = Organization::factory()->create();
    setPermissionsTeamId($organization->id);

    foreach (DeviceProfilePermission::cases() as $permission) {
        Permission::findOrCreate($permission->value, 'web');
    }
});

it('allows user with viewAny permission to view device profiles index', function (): void {
    $user = User::factory()->create();
    $role = Role::create(['name' => 'Viewer', 'guard_name' => 'web']);
    $role->givePermissionTo(DeviceProfilePermission::VIEW_ANY->value);
    $user->assignRole($role);

    expect($user->can('viewAny', DeviceProfile::class))->toBeTrue();
});

it('denies user without viewAny permission to view device profiles index', function (): void {
    $user = User::factory()->create();

    expect($user->can('viewAny', DeviceProfile::class))->toBeFalse();
});

it('allows user with view permission to view a device profile', function (): void {
    $user = User::factory()->create();
    $role = Role::create(['name' => 'Viewer', 'guard_name' => 'web']);
    $role->givePermissionTo(DeviceProfilePermission::VIEW->value);
    $user->assignRole($role);

    $profile = DeviceProfile::factory()->create();

    expect($user->can('view', $profile))->toBeTrue();
});

it('denies user without view permission to view a device profile', function (): void {
    $user = User::factory()->create();
    $profile = DeviceProfile::factory()->create();

    expect($user->can('view', $profile))->toBeFalse();
});

it('allows user with create permission to create device profiles', function (): void {
    $user = User::factory()->create();
    $role = Role::create(['name' => 'Creator', 'guard_name' => 'web']);
    $role->givePermissionTo(DeviceProfilePermission::CREATE->value);
    $user->assignRole($role);

    expect($user->can('create', DeviceProfile::class))->toBeTrue();
});

it('denies user without create permission to create device profiles', function (): void {
    $user = User::factory()->create();

    expect($user->can('create', DeviceProfile::class))->toBeFalse();
});

it('allows user with update permission to update a device profile', function (): void {
    $user = User::factory()->create();
    $role = Role::create(['name' => 'Editor', 'guard_name' => 'web']);
    $role->givePermissionTo(DeviceProfilePermission::UPDATE->value);
    $user->assignRole($role);

    $profile = DeviceProfile::factory()->create();

    expect($user->can('update', $profile))->toBeTrue();
});

it('denies user without update permission to update a device profile', function (): void {
    $user = User::factory()->create();
    $profile = DeviceProfile::factory()->create();

    expect($user->can('update', $profile))->toBeFalse();
});

it('allows user with delete permission to delete a device profile', function (): void {
    $user = User::factory()->create();
    $role = Role::create(['name' => 'Admin', 'guard_name' => 'web']);
    $role->givePermissionTo(DeviceProfilePermission::DELETE->value);
    $user->assignRole($role);

    $profile = DeviceProfile::factory()->create();

    expect($user->can('delete', $profile))->toBeTrue();
});

it('denies user without delete permission to delete a device profile', function (): void {
    $user = User::factory()->create();
    $profile = DeviceProfile::factory()->create();

    expect($user->can('delete', $profile))->toBeFalse();
});

it('super admin can perform all actions on device profiles', function (): void {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $profile = DeviceProfile::factory()->create();

    expect($superAdmin->can('viewAny', DeviceProfile::class))->toBeTrue()
        ->and($superAdmin->can('view', $profile))->toBeTrue()
        ->and($superAdmin->can('create', DeviceProfile::class))->toBeTrue()
        ->and($superAdmin->can('update', $profile))->toBeTrue()
        ->and($superAdmin->can('delete', $profile))->toBeTrue();
});
