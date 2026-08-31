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

    protected function parentHubImeiFor(array $deviceConfig): ?string
    {
        $parentHubImei = $deviceConfig['parent_hub_imei'] ?? $deviceConfig['hub_imei'] ?? null;

        return is_string($parentHubImei) && $parentHubImei !== ''
            ? $parentHubImei
            : null;
    }
}
