<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Permissions\DevicePermission;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use App\Domain\Telemetry\Permissions\DeviceTelemetryLogPermission;
use Database\Seeders\PortalUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (PortalUserSeeder::ACCOUNTS as $slug => $account) {
        Organization::factory()->create([
            'name' => $account['name'].' Organization',
            'slug' => $slug,
        ]);
    }
});

it('seeds idempotent organization-scoped portal viewers with only view permissions', function (): void {
    $incorrectOrganization = Organization::factory()->create(['slug' => 'incorrect-organization']);
    $existingUser = User::factory()->unverified()->create([
        'email' => PortalUserSeeder::ACCOUNTS['teejay']['email'],
        'is_super_admin' => true,
    ]);
    $existingUser->organizations()->sync([$incorrectOrganization->id]);
    setPermissionsTeamId($incorrectOrganization->id);
    $incorrectRole = Role::query()->create([
        'organization_id' => $incorrectOrganization->id,
        'name' => 'incorrect-role',
        'guard_name' => 'web',
    ]);
    $existingUser->assignRole($incorrectRole);

    $this->seed(PortalUserSeeder::class);

    $userIds = User::query()
        ->whereIn('email', collect(PortalUserSeeder::ACCOUNTS)->pluck('email'))
        ->pluck('id', 'email');

    $this->seed(PortalUserSeeder::class);

    $expectedPermissions = collect([
        DevicePermission::VIEW_ANY->value,
        DevicePermission::VIEW->value,
        DeviceTelemetryLogPermission::VIEW_ANY->value,
        DeviceTelemetryLogPermission::VIEW->value,
    ])->sort()->values()->all();

    expect($userIds)->toHaveCount(3)
        ->and(Role::query()->where('name', PortalUserSeeder::VIEWER_ROLE)->count())->toBe(3)
        ->and(Permission::query()->whereIn('name', $expectedPermissions)->count())->toBe(4);

    foreach (PortalUserSeeder::ACCOUNTS as $organizationSlug => $account) {
        $organization = Organization::query()->where('slug', $organizationSlug)->firstOrFail();
        $user = User::query()->where('email', $account['email'])->firstOrFail();

        setPermissionsTeamId($organization->id);

        $role = $user->roles()
            ->wherePivot('organization_id', $organization->id)
            ->firstOrFail();

        expect($user->id)->toBe($userIds->get($account['email']))
            ->and($user->name)->toBe($account['name'])
            ->and($user->email_verified_at)->not->toBeNull()
            ->and($user->isSuperAdmin())->toBeFalse()
            ->and(Hash::check(PortalUserSeeder::DEFAULT_PASSWORD, $user->password))->toBeTrue()
            ->and($user->organizations()->pluck('organizations.id')->all())->toBe([$organization->id])
            ->and($user->roles()->count())->toBe(1)
            ->and($role->name)->toBe(PortalUserSeeder::VIEWER_ROLE)
            ->and($role->permissions()->pluck('name')->sort()->values()->all())->toBe($expectedPermissions);
    }
});

it('refuses to create demo portal users in production', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');

    try {
        expect(fn () => (new PortalUserSeeder)->run())
            ->toThrow(LogicException::class, 'Portal demo users cannot be seeded in production.');
    } finally {
        $this->app->detectEnvironment(fn (): string => 'testing');
    }

    expect(User::query()->whereIn('email', collect(PortalUserSeeder::ACCOUNTS)->pluck('email'))->exists())
        ->toBeFalse();
});
