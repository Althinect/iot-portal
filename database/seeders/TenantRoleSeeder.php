<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Authorization\Services\TenantRoleManager;
use App\Domain\Shared\Models\Organization;
use Illuminate\Database\Seeder;

class TenantRoleSeeder extends Seeder
{
    public function run(TenantRoleManager $roleManager): void
    {
        Organization::query()
            ->lazyById()
            ->each(fn (Organization $organization) => $roleManager->syncForOrganization($organization));
    }
}
