<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Authorization\Enums\TenantRole;
use App\Domain\Authorization\Services\TenantRoleManager;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use Illuminate\Database\Seeder;
use LogicException;
use Spatie\Permission\PermissionRegistrar;

class PortalUserSeeder extends Seeder
{
    public const string DEFAULT_PASSWORD = 'PortalDemo123!';

    public const string VIEWER_ROLE = 'viewer';

    /**
     * @var array<string, array{name: string, email: string}>
     */
    public const array ACCOUNTS = [
        'teejay' => [
            'name' => 'Teejay Portal User',
            'email' => 'portal@teejay.test',
        ],
        'miracle-dome' => [
            'name' => 'Miracle Dome Portal User',
            'email' => 'portal@miracle-dome.test',
        ],
        'srilankan-airlines' => [
            'name' => 'SriLankan Airlines Portal User',
            'email' => 'portal@srilankan-airlines.test',
        ],
    ];

    public function run(TenantRoleManager $roleManager): void
    {
        if (app()->environment('production')) {
            throw new LogicException('Portal demo users cannot be seeded in production.');
        }

        $previousPermissionsTeamId = getPermissionsTeamId();

        try {
            foreach (self::ACCOUNTS as $organizationSlug => $account) {
                $organization = Organization::query()
                    ->where('slug', $organizationSlug)
                    ->firstOrFail();

                $user = User::query()->updateOrCreate(
                    ['email' => $account['email']],
                    [
                        'name' => $account['name'],
                        'password' => self::DEFAULT_PASSWORD,
                        'is_super_admin' => false,
                    ],
                );

                $user->forceFill(['email_verified_at' => now()])->save();
                $user->organizations()->sync([$organization->id]);

                $roleManager->assign($user, $organization, TenantRole::Viewer);
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        } finally {
            setPermissionsTeamId($previousPermissionsTeamId);
        }
    }
}
