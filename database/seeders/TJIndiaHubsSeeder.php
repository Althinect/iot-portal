<?php

declare(strict_types=1);

namespace Database\Seeders;

class TJIndiaHubsSeeder extends TJIndiaMigrationSeederSupport
{
    public function run(): void
    {
        $organization = $this->ensureOrganization();

        $this->ensureHubs($organization);
        $this->cleanupHubs($organization);
    }
}
