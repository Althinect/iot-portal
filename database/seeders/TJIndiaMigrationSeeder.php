<?php

declare(strict_types=1);

namespace Database\Seeders;

class TJIndiaMigrationSeeder extends TJIndiaMigrationSeederSupport
{
    public const ORGANIZATION_SLUG = 'tj-india';

    public const ORGANIZATION_NAME = 'TJ India';

    public function run(): void
    {
        $this->ensureOrganization();

        $this->call([
            TJIndiaHubsSeeder::class,
            TJIndiaFabricLengthSeeder::class,
            TJIndiaStatusSeeder::class,
            TJIndiaStenterSeeder::class,
        ]);
    }
}
