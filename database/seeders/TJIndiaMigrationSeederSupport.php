<?php

declare(strict_types=1);

namespace Database\Seeders;

class TJIndiaMigrationSeederSupport extends LegacyImoniMigrationSeederSupport
{
    protected function organizationSlug(): string
    {
        return TJIndiaMigrationSeeder::ORGANIZATION_SLUG;
    }

    protected function organizationName(): string
    {
        return TJIndiaMigrationSeeder::ORGANIZATION_NAME;
    }

    protected function hubInventory(): array
    {
        return TJIndiaMigrationInventory::hubs();
    }
}
