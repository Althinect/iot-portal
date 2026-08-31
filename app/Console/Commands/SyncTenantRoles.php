<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Authorization\Services\TenantRoleManager;
use App\Domain\Shared\Models\Organization;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tenant:sync-roles {--organization= : Organization ID or slug; omit to sync all organizations}')]
#[Description('Create and synchronize the protected tenant roles and their permissions')]
class SyncTenantRoles extends Command
{
    public function handle(TenantRoleManager $roleManager): int
    {
        $organizationFilter = $this->option('organization');
        $organizations = Organization::query()
            ->when(
                is_string($organizationFilter) && $organizationFilter !== '',
                fn ($query) => $query->where(function ($query) use ($organizationFilter): void {
                    $query->where('slug', $organizationFilter);

                    if (ctype_digit($organizationFilter)) {
                        $query->orWhereKey((int) $organizationFilter);
                    }
                }),
            )
            ->get();

        if ($organizations->isEmpty()) {
            if (! is_string($organizationFilter) || $organizationFilter === '') {
                $this->components->info('No organizations need tenant-role synchronization.');

                return self::SUCCESS;
            }

            $this->components->error('No matching organizations were found.');

            return self::FAILURE;
        }

        $organizations->each(function (Organization $organization) use ($roleManager): void {
            $roleManager->syncForOrganization($organization);
            $this->components->info("Synchronized tenant roles for {$organization->name}.");
        });

        return self::SUCCESS;
    }
}
