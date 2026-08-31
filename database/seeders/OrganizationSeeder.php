<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Authorization\Enums\TenantRole;
use App\Domain\Authorization\Services\TenantRoleManager;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use Illuminate\Database\Seeder;

class OrganizationSeeder extends Seeder
{
    private const string DEFAULT_ORGANIZATION_NAME = 'Main Organization';

    private const string DEFAULT_ORGANIZATION_SLUG = 'main-organization';

    private const string DEFAULT_ORGANIZATION_ADMIN_EMAIL = 'org-admin@admin.com';

    public function run(TenantRoleManager $roleManager): void
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

            $roleManager->assign($adminUser, $organization, TenantRole::TenantAdmin);
        } finally {
            setPermissionsTeamId($previousPermissionsTeamId);
        }
    }
}
