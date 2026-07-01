<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\DeviceProfile\Models\DeviceProfileVersion;

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

    protected function displayNameForProfile(string $name, DeviceProfileVersion $profileVersion): string
    {
        return $this->energyProfileDisplayName($name, $profileVersion);
    }
}
