<?php

declare(strict_types=1);

use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Permissions\DeviceProfileVersionPermission;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $organization = Organization::factory()->create();
    setPermissionsTeamId($organization->id);

    foreach (DeviceProfileVersionPermission::cases() as $permission) {
        Permission::findOrCreate($permission->value, 'web');
    }
});

it('allows user with viewAny permission to view profile versions index', function (): void {
    $user = User::factory()->create();
    $role = Role::create(['name' => 'Viewer', 'guard_name' => 'web']);
    $role->givePermissionTo(DeviceProfileVersionPermission::VIEW_ANY->value);
    $user->assignRole($role);

    expect($user->can('viewAny', DeviceProfileVersion::class))->toBeTrue();
});

it('denies user without viewAny permission to view profile versions index', function (): void {
    $user = User::factory()->create();

    expect($user->can('viewAny', DeviceProfileVersion::class))->toBeFalse();
});

it('allows user with view permission to view a profile version', function (): void {
    $user = User::factory()->create();
    $role = Role::create(['name' => 'Viewer', 'guard_name' => 'web']);
    $role->givePermissionTo(DeviceProfileVersionPermission::VIEW->value);
    $user->assignRole($role);

    $version = DeviceProfileVersion::factory()->create();

    expect($user->can('view', $version))->toBeTrue();
});

it('denies user without view permission to view a profile version', function (): void {
    $user = User::factory()->create();
    $version = DeviceProfileVersion::factory()->create();

    expect($user->can('view', $version))->toBeFalse();
});

it('allows user with create permission to create profile versions', function (): void {
    $user = User::factory()->create();
    $role = Role::create(['name' => 'Creator', 'guard_name' => 'web']);
    $role->givePermissionTo(DeviceProfileVersionPermission::CREATE->value);
    $user->assignRole($role);

    expect($user->can('create', DeviceProfileVersion::class))->toBeTrue();
});

it('denies user without create permission to create profile versions', function (): void {
    $user = User::factory()->create();

    expect($user->can('create', DeviceProfileVersion::class))->toBeFalse();
});

it('allows user with update permission to update a profile version', function (): void {
    $user = User::factory()->create();
    $role = Role::create(['name' => 'Editor', 'guard_name' => 'web']);
    $role->givePermissionTo(DeviceProfileVersionPermission::UPDATE->value);
    $user->assignRole($role);

    $version = DeviceProfileVersion::factory()->create();

    expect($user->can('update', $version))->toBeTrue();
});

it('denies user without update permission to update a profile version', function (): void {
    $user = User::factory()->create();
    $version = DeviceProfileVersion::factory()->create();

    expect($user->can('update', $version))->toBeFalse();
});

it('allows user with delete permission to delete a profile version', function (): void {
    $user = User::factory()->create();
    $role = Role::create(['name' => 'Admin', 'guard_name' => 'web']);
    $role->givePermissionTo(DeviceProfileVersionPermission::DELETE->value);
    $user->assignRole($role);

    $version = DeviceProfileVersion::factory()->create();

    expect($user->can('delete', $version))->toBeTrue();
});

it('denies user without delete permission to delete a profile version', function (): void {
    $user = User::factory()->create();
    $version = DeviceProfileVersion::factory()->create();

    expect($user->can('delete', $version))->toBeFalse();
});

it('super admin can perform all actions on profile versions', function (): void {
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $version = DeviceProfileVersion::factory()->create();

    expect($superAdmin->can('viewAny', DeviceProfileVersion::class))->toBeTrue()
        ->and($superAdmin->can('view', $version))->toBeTrue()
        ->and($superAdmin->can('create', DeviceProfileVersion::class))->toBeTrue()
        ->and($superAdmin->can('update', $version))->toBeTrue()
        ->and($superAdmin->can('delete', $version))->toBeTrue();
});
