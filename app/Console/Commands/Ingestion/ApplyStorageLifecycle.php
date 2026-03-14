<?php

declare(strict_types=1);

namespace App\Console\Commands\Ingestion;

use App\Domain\DataIngestion\Models\IngestionMessage;
use App\Domain\DataIngestion\Models\IngestionStageLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ApplyStorageLifecycle extends Command
{
    protected $signature = 'ingestion:apply-storage-lifecycle';

    protected $description = 'Apply telemetry storage lifecycle policies and prune ingestion side tables.';

    public function handle(): int
    {
        $telemetrySummary = $this->applyTelemetryLifecyclePolicies();
        $prunedStageLogs = $this->pruneStageLogs();
        $prunedMessages = $this->pruneMessages();

        $this->info("Telemetry lifecycle: {$telemetrySummary}");
        $this->info("Pruned ingestion stage logs: {$prunedStageLogs}");
        $this->info("Pruned ingestion messages: {$prunedMessages}");

        return self::SUCCESS;
    }

    private function applyTelemetryLifecyclePolicies(): string
    {
        if (DB::getDriverName() !== 'pgsql') {
            return 'skipped Timescale policies on non-Postgres connection';
        }

        if (! $this->timescaleExtensionInstalled()) {
            return 'skipped Timescale policies because the timescaledb extension is not installed';
        }

        if (! $this->telemetryHypertableExists()) {
            return 'skipped Timescale policies because device_telemetry_logs is not a hypertable';
        }

        $chunkInterval = $this->validatedInterval('ingestion.telemetry_chunk_interval', '1 day');
        $compressAfter = $this->validatedInterval('ingestion.telemetry_compress_after', '7 days');
        $retention = $this->validatedInterval('ingestion.telemetry_retention', '90 days');

        DB::statement(<<<'SQL'
            ALTER TABLE device_telemetry_logs SET (
                timescaledb.compress,
                timescaledb.compress_orderby = 'recorded_at DESC',
                timescaledb.compress_segmentby = 'device_id, schema_version_topic_id'
            )
        SQL);

        DB::select('SELECT set_chunk_time_interval(?, ?::interval)', ['device_telemetry_logs', $chunkInterval]);
        DB::select("SELECT remove_compression_policy('device_telemetry_logs', if_exists => TRUE)");
        DB::select('SELECT add_compression_policy(?, compress_after => ?::interval)', ['device_telemetry_logs', $compressAfter]);
        DB::select("SELECT remove_retention_policy('device_telemetry_logs', if_exists => TRUE)");
        DB::select('SELECT add_retention_policy(?, drop_after => ?::interval)', ['device_telemetry_logs', $retention]);

        return "chunk interval {$chunkInterval}, compression after {$compressAfter}, retention {$retention}";
    }

    private function pruneStageLogs(): int
    {
        $cutoff = now()->subDays($this->retentionDays('ingestion.ingestion_stage_log_retention_days', 7));
        $batchSize = $this->pruneBatchSize();
        $deletedRows = 0;

        do {
            $ids = IngestionStageLog::query()
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->limit($batchSize)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deletedRows += $this->normalizeDeletedRows(
                IngestionStageLog::query()->whereIn('id', $ids)->delete(),
            );
        } while (true);

        return $deletedRows;
    }

    private function pruneMessages(): int
    {
        $cutoff = now()->subDays($this->retentionDays('ingestion.ingestion_message_retention_days', 14));
        $batchSize = $this->pruneBatchSize();
        $deletedRows = 0;

        do {
            $ids = IngestionMessage::query()
                ->where('received_at', '<', $cutoff)
                ->orderBy('received_at')
                ->limit($batchSize)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deletedRows += $this->normalizeDeletedRows(
                IngestionMessage::query()->whereIn('id', $ids)->delete(),
            );
        } while (true);

        return $deletedRows;
    }

    private function timescaleExtensionInstalled(): bool
    {
        $row = DB::selectOne("SELECT EXISTS (SELECT 1 FROM pg_extension WHERE extname = 'timescaledb') AS present");

        return $this->truthyDatabaseBoolean($this->databaseBooleanResult($row));
    }

    private function telemetryHypertableExists(): bool
    {
        $row = DB::selectOne(
            "SELECT EXISTS (
                SELECT 1
                FROM timescaledb_information.hypertables
                WHERE hypertable_schema = 'public'
                  AND hypertable_name = 'device_telemetry_logs'
            ) AS present"
        );

        return $this->truthyDatabaseBoolean($this->databaseBooleanResult($row));
    }

    private function truthyDatabaseBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 't', 'true'], true);
        }

        return false;
    }

    private function databaseBooleanResult(mixed $row): mixed
    {
        if (! is_object($row) || ! property_exists($row, 'present')) {
            return false;
        }

        return $row->present;
    }

    private function normalizeDeletedRows(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }

    private function pruneBatchSize(): int
    {
        $configuredValue = config('ingestion.storage_prune_batch_size', 1000);

        return max(1, is_numeric($configuredValue) ? (int) $configuredValue : 1000);
    }

    private function retentionDays(string $configKey, int $default): int
    {
        $configuredValue = config($configKey, $default);

        return max(1, is_numeric($configuredValue) ? (int) $configuredValue : $default);
    }

    private function validatedInterval(string $configKey, string $default): string
    {
        $configuredValue = config($configKey, $default);

        if (! is_scalar($configuredValue)) {
            $configuredValue = $default;
        }

        $configuredValue = trim((string) $configuredValue);

        if (preg_match('/^\d+\s+(minute|minutes|hour|hours|day|days|week|weeks|month|months)$/i', $configuredValue) === 1) {
            return strtolower($configuredValue);
        }

        throw new \RuntimeException("Invalid Timescale interval configured for [{$configKey}]: [{$configuredValue}]");
    }
}
