<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class OrganizationSeeder extends Seeder
{
    private const DEFAULT_ORGANIZATION_NAME = 'Main Organization';

    private const DEFAULT_ORGANIZATION_SLUG = 'main-organization';

    private const DEFAULT_ORGANIZATION_ADMIN_EMAIL = 'org-admin@admin.com';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $organization = Organization::query()->firstOrCreate(
            ['slug' => self::DEFAULT_ORGANIZATION_SLUG],
            ['name' => self::DEFAULT_ORGANIZATION_NAME],
        );

        Organization::query()
            ->where('id', '!=', $organization->id)
            ->delete();

        $previousPermissionsTeamId = getPermissionsTeamId();
        setPermissionsTeamId($organization->id);

        try {
            /** @var User $adminUser */
            $adminUser = User::query()->firstOrCreate(
                ['email' => self::DEFAULT_ORGANIZATION_ADMIN_EMAIL],
                [
                    'name' => 'Organization Admin',
                    'password' => 'password',
                    'is_super_admin' => false,
                ],
            );

            $organization->users()->syncWithoutDetaching([$adminUser->id]);

            /** @var User|null $superAdmin */
            $superAdmin = User::query()
                ->where('email', UserSeeder::DEFAULT_SUPER_ADMIN_EMAIL)
                ->first();

            if ($superAdmin instanceof User) {
                $organization->users()->syncWithoutDetaching([$superAdmin->id]);
            }

            $role = $organization->roles()->firstOrCreate([
                'name' => 'admin',
                'guard_name' => 'web',
            ]);

            $permissions = Permission::query()->pluck('name')->all();
            $role->syncPermissions($permissions);
            $adminUser->assignRole($role);
        } finally {
            setPermissionsTeamId($previousPermissionsTeamId);
        }
    }
}
