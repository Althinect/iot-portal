<?php

declare(strict_types=1);

use App\Domain\Authorization\Enums\TenantRole;
use App\Domain\Authorization\Services\TenantRoleManager;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
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

    $expectedPermissions = collect(
        app(TenantRoleManager::class)->permissionsFor(TenantRole::Viewer),
    )->sort()->values()->all();
    $portalOrganizationIds = Organization::query()
        ->whereIn('slug', array_keys(PortalUserSeeder::ACCOUNTS))
        ->pluck('id');

    expect($userIds)->toHaveCount(3)
        ->and(Role::query()
            ->whereIn('organization_id', $portalOrganizationIds)
            ->where('name', PortalUserSeeder::VIEWER_ROLE)
            ->count())->toBe(3)
        ->and(Permission::query()->whereIn('name', $expectedPermissions)->count())
        ->toBe(count($expectedPermissions));

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
        expect(fn () => app()->call([new PortalUserSeeder, 'run']))
            ->toThrow(LogicException::class, 'Portal demo users cannot be seeded in production.');
    } finally {
        $this->app->detectEnvironment(fn (): string => 'testing');
    }

    expect(User::query()->whereIn('email', collect(PortalUserSeeder::ACCOUNTS)->pluck('email'))->exists())
        ->toBeFalse();
});
