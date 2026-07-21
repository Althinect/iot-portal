<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\DeviceManagement\Permissions\DevicePermission;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use App\Domain\Telemetry\Permissions\DeviceTelemetryLogPermission;
use Illuminate\Database\Seeder;
use LogicException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PortalUserSeeder extends Seeder
{
    public const string DEFAULT_PASSWORD = 'PortalDemo123!';

    public const string VIEWER_ROLE = 'portal-viewer';

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

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new LogicException('Portal demo users cannot be seeded in production.');
        }

        $permissions = collect([
            DevicePermission::VIEW_ANY->value,
            DevicePermission::VIEW->value,
            DeviceTelemetryLogPermission::VIEW_ANY->value,
            DeviceTelemetryLogPermission::VIEW->value,
        ])->map(fn (string $permission): Permission => Permission::findOrCreate($permission, 'web'));

        $previousPermissionsTeamId = getPermissionsTeamId();

        try {
            foreach (self::ACCOUNTS as $organizationSlug => $account) {
                $organization = Organization::query()
                    ->where('slug', $organizationSlug)
                    ->firstOrFail();

                setPermissionsTeamId($organization->id);

                $user = User::query()->updateOrCreate(
                    ['email' => $account['email']],
                    [
                        'name' => $account['name'],
                        'password' => self::DEFAULT_PASSWORD,
                        'is_super_admin' => false,
                    ],
                );

                $user->forceFill([
                    'email_verified_at' => now(),
                ])->save();

                $user->organizations()->sync([$organization->id]);

                $role = $organization->roles()->firstOrCreate([
                    'name' => self::VIEWER_ROLE,
                    'guard_name' => 'web',
                ]);

                $role->syncPermissions($permissions);
                $user->roles()->detach();
                $user->assignRole($role);
            }
        } finally {
            setPermissionsTeamId($previousPermissionsTeamId);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
}
