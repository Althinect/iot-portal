<?php

declare(strict_types=1);

namespace Database\Seeders;

class TeejayMigrationSeederSupport extends LegacyImoniMigrationSeederSupport
{
    protected function organizationSlug(): string
    {
        return TeejayMigrationSeeder::ORGANIZATION_SLUG;
    }

    protected function organizationName(): string
    {
        return TeejayMigrationSeeder::ORGANIZATION_NAME;
    }

    protected function hubInventory(): array
    {
        return TeejayMigrationInventory::hubs();
    }

    /**
     * @return array<string, array<string, array<int, string>>>
     */
    protected function specialDecodeProfiles(): array
    {
        return [
            'bigEndianFloat32' => [
                '869604063871346' => ['51', '52', '53', '54', '55'],
                '869604063866593' => ['51', '52', '53', '54', '55', '56', '57', '58'],
                '869604063870249' => ['51', '52', '53', '54', '55'],
                '869604063874209' => ['51', '52', '53', '54', '55', '56', '57', '58', '59', '5C'],
                '169604063874209' => ['51', '52', '53', '54', '55', '56', '57', '58', '59'],
                '869604063849748' => ['51'],
                '869604063845217' => ['51', '52', '53', '54', '55'],
            ],
            'twosComplement' => [
                '869604063859564' => ['51', '52', '53'],
            ],
        ];
    }
}
