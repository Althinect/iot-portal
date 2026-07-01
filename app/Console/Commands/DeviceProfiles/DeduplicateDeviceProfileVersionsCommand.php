<?php

declare(strict_types=1);

namespace App\Console\Commands\DeviceProfiles;

use App\Domain\DeviceProfile\Services\DeviceProfileVersionDeduplicator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('device-profiles:deduplicate-versions {--force : Delete duplicate versions after remapping references}')]
#[Description('Find duplicate device profile version configurations and optionally consolidate them.')]
class DeduplicateDeviceProfileVersionsCommand extends Command
{
    public function handle(DeviceProfileVersionDeduplicator $deduplicator): int
    {
        $mappings = $deduplicator->duplicateMappings();

        if ($mappings === []) {
            $this->info('No duplicate device profile versions found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Profile', 'Duplicate', 'Canonical', 'Signature'],
            array_map(
                fn (array $mapping): array => [
                    $mapping['profile_key'],
                    "v{$mapping['duplicate_version']} #{$mapping['duplicate_id']}",
                    "v{$mapping['canonical_version']} #{$mapping['canonical_id']}",
                    substr((string) $mapping['signature'], 0, 12),
                ],
                $mappings,
            ),
        );

        if (! (bool) $this->option('force')) {
            $this->warn('Dry run only. Re-run with --force to remap references and delete duplicate version rows.');

            return self::SUCCESS;
        }

        $summary = $deduplicator->deduplicate();

        $this->info(
            "Deleted {$summary['deleted_versions']} duplicate device profile versions after remapping {$summary['remapped_rows']} referenced rows."
        );

        return self::SUCCESS;
    }
}
