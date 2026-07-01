<?php

declare(strict_types=1);

namespace App\Domain\DeviceProfile\Services;

use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\DeviceProfile;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class DeviceProfileVersionDeduplicator
{
    /**
     * @var array<int, string>
     */
    private const VERSION_REFERENCE_TABLES = [
        'devices',
        'device_telemetry_logs',
        'ingestion_messages',
    ];

    /**
     * @var array<int, string>
     */
    private const CHANNEL_REFERENCE_TABLES = [
        'automation_telemetry_triggers',
        'device_desired_channel_states',
        'device_signal_bindings',
        'device_telemetry_logs',
        'ingestion_messages',
        'iot_dashboard_widgets',
        'threshold_policies',
    ];

    public function __construct(private readonly DeviceProfileVersionConfigurationHasher $hasher) {}

    /**
     * @return array<int, array{
     *     profile_id: int,
     *     profile_key: string,
     *     signature: string,
     *     canonical_id: int,
     *     canonical_version: int,
     *     duplicate_id: int,
     *     duplicate_version: int
     * }>
     */
    public function duplicateMappings(): array
    {
        if (! Schema::hasTable('device_profiles') || ! Schema::hasTable('device_profile_versions')) {
            return [];
        }

        $mappings = [];

        DeviceProfile::query()
            ->with([
                'versions' => fn ($query) => $query
                    ->with(['channels.parameters', 'derivedParameters'])
                    ->orderBy('version')
                    ->orderBy('id'),
            ])
            ->orderBy('id')
            ->get()
            ->each(function (DeviceProfile $profile) use (&$mappings): void {
                $canonicalBySignature = [];

                foreach ($profile->versions as $version) {
                    $signature = $this->hasher->signature($version);
                    $canonical = $canonicalBySignature[$signature] ?? null;

                    if (! $canonical instanceof DeviceProfileVersion) {
                        $canonicalBySignature[$signature] = $version;

                        continue;
                    }

                    $mappings[] = [
                        'profile_id' => (int) $profile->id,
                        'profile_key' => $profile->key,
                        'signature' => $signature,
                        'canonical_id' => (int) $canonical->id,
                        'canonical_version' => (int) $canonical->version,
                        'duplicate_id' => (int) $version->id,
                        'duplicate_version' => (int) $version->version,
                    ];
                }
            });

        return $mappings;
    }

    /**
     * @return array{duplicates: int, remapped_rows: int, deleted_versions: int}
     */
    public function deduplicate(bool $dryRun = false): array
    {
        $mappings = $this->duplicateMappings();

        if ($dryRun) {
            return [
                'duplicates' => count($mappings),
                'remapped_rows' => 0,
                'deleted_versions' => 0,
            ];
        }

        $remappedRows = 0;
        $deletedVersions = 0;

        DB::transaction(function () use ($mappings, &$remappedRows, &$deletedVersions): void {
            foreach ($mappings as $mapping) {
                $remappedRows += $this->mergeDuplicateVersion(
                    duplicateVersionId: $mapping['duplicate_id'],
                    canonicalVersionId: $mapping['canonical_id'],
                );

                $deletedVersions++;
            }
        });

        return [
            'duplicates' => count($mappings),
            'remapped_rows' => $remappedRows,
            'deleted_versions' => $deletedVersions,
        ];
    }

    private function mergeDuplicateVersion(int $duplicateVersionId, int $canonicalVersionId): int
    {
        $duplicate = DeviceProfileVersion::query()
            ->with('channels')
            ->findOrFail($duplicateVersionId);
        $canonical = DeviceProfileVersion::query()
            ->with('channels')
            ->findOrFail($canonicalVersionId);

        $channelMap = $this->channelMap($duplicate, $canonical);
        $remappedRows = 0;

        $this->deleteConflictingChannelRows($channelMap);

        foreach ($channelMap as $duplicateChannelId => $canonicalChannelId) {
            $remappedRows += $this->replaceColumnValue(
                tables: self::CHANNEL_REFERENCE_TABLES,
                column: 'device_channel_id',
                from: $duplicateChannelId,
                to: $canonicalChannelId,
            );
        }

        $remappedRows += $this->replaceColumnValue(
            tables: self::VERSION_REFERENCE_TABLES,
            column: 'device_profile_version_id',
            from: $duplicateVersionId,
            to: $canonicalVersionId,
        );

        $duplicate->delete();

        return $remappedRows;
    }

    /**
     * @return array<int, int>
     */
    private function channelMap(DeviceProfileVersion $duplicate, DeviceProfileVersion $canonical): array
    {
        $canonicalChannels = $canonical->channels->keyBy('key');
        $channelMap = [];

        foreach ($duplicate->channels as $duplicateChannel) {
            $canonicalChannel = $canonicalChannels->get($duplicateChannel->key);

            if (! $canonicalChannel instanceof DeviceChannel) {
                throw new RuntimeException("Canonical profile version [{$canonical->id}] is missing channel [{$duplicateChannel->key}].");
            }

            $channelMap[(int) $duplicateChannel->id] = (int) $canonicalChannel->id;
        }

        return $channelMap;
    }

    /**
     * @param  array<int, int>  $channelMap
     */
    private function deleteConflictingChannelRows(array $channelMap): void
    {
        foreach ($channelMap as $duplicateChannelId => $canonicalChannelId) {
            if ($this->hasColumn('device_desired_channel_states', 'device_channel_id')) {
                DB::delete(
                    <<<'SQL'
                    DELETE FROM device_desired_channel_states duplicate_rows
                    WHERE duplicate_rows.device_channel_id = ?
                    AND EXISTS (
                        SELECT 1
                        FROM device_desired_channel_states canonical_rows
                        WHERE canonical_rows.device_channel_id = ?
                        AND canonical_rows.device_id = duplicate_rows.device_id
                    )
                    SQL,
                    [$duplicateChannelId, $canonicalChannelId],
                );
            }

            if ($this->hasColumn('device_signal_bindings', 'device_channel_id')) {
                DB::delete(
                    <<<'SQL'
                    DELETE FROM device_signal_bindings duplicate_rows
                    WHERE duplicate_rows.device_channel_id = ?
                    AND EXISTS (
                        SELECT 1
                        FROM device_signal_bindings canonical_rows
                        WHERE canonical_rows.device_channel_id = ?
                        AND canonical_rows.device_id = duplicate_rows.device_id
                        AND canonical_rows.parameter_key = duplicate_rows.parameter_key
                    )
                    SQL,
                    [$duplicateChannelId, $canonicalChannelId],
                );
            }
        }
    }

    /**
     * @param  array<int, string>  $tables
     */
    private function replaceColumnValue(array $tables, string $column, int $from, int $to): int
    {
        $updatedRows = 0;

        foreach ($tables as $table) {
            if (! $this->hasColumn($table, $column)) {
                continue;
            }

            $updatedRows += DB::table($table)
                ->where($column, $from)
                ->update([$column => $to]);
        }

        return $updatedRows;
    }

    private function hasColumn(string $table, string $column): bool
    {
        return Schema::hasTable($table) && Schema::hasColumn($table, $column);
    }
}
