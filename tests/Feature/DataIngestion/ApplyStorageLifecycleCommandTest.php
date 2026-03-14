<?php

declare(strict_types=1);

use App\Domain\DataIngestion\Models\IngestionMessage;
use App\Domain\DataIngestion\Models\IngestionStageLog;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Carbon::setTestNow();
});

it('applies telemetry lifecycle policies and prunes expired ingestion side tables', function (): void {
    Carbon::setTestNow('2026-03-09 12:00:00');

    config()->set('ingestion.telemetry_chunk_interval', '1 day');
    config()->set('ingestion.telemetry_compress_after', '7 days');
    config()->set('ingestion.telemetry_retention', '90 days');
    config()->set('ingestion.ingestion_message_retention_days', 14);
    config()->set('ingestion.ingestion_stage_log_retention_days', 7);
    config()->set('ingestion.storage_prune_batch_size', 10);

    $expiredMessage = IngestionMessage::factory()->create([
        'received_at' => now()->subDays(20),
        'created_at' => now()->subDays(20),
        'updated_at' => now()->subDays(20),
    ]);

    $freshMessage = IngestionMessage::factory()->create([
        'received_at' => now()->subDays(2),
        'created_at' => now()->subDays(2),
        'updated_at' => now()->subDays(2),
    ]);

    $expiredStageLog = IngestionStageLog::factory()->create([
        'ingestion_message_id' => $freshMessage->id,
        'created_at' => now()->subDays(10),
    ]);

    $freshStageLog = IngestionStageLog::factory()->create([
        'ingestion_message_id' => $freshMessage->id,
        'created_at' => now()->subDay(),
    ]);

    IngestionStageLog::factory()->create([
        'ingestion_message_id' => $expiredMessage->id,
        'created_at' => now()->subDays(20),
    ]);

    $command = $this->artisan('ingestion:apply-storage-lifecycle')
        ->expectsOutputToContain('Pruned ingestion stage logs: 2')
        ->expectsOutputToContain('Pruned ingestion messages: 1');

    if (DB::getDriverName() === 'pgsql') {
        $command->expectsOutputToContain('Telemetry lifecycle: chunk interval 1 day, compression after 7 days, retention 90 days');
    } else {
        $command->expectsOutputToContain('Telemetry lifecycle: skipped Timescale policies on non-Postgres connection');
    }

    $command->assertSuccessful();

    if (DB::getDriverName() !== 'pgsql') {
        return;
    }

    expect(IngestionMessage::query()->whereKey($expiredMessage->id)->exists())->toBeFalse()
        ->and(IngestionMessage::query()->whereKey($freshMessage->id)->exists())->toBeTrue()
        ->and(IngestionStageLog::query()->whereKey($expiredStageLog->id)->exists())->toBeFalse()
        ->and(IngestionStageLog::query()->whereKey($freshStageLog->id)->exists())->toBeTrue();

    $dimension = DB::selectOne(
        "SELECT time_interval
        FROM timescaledb_information.dimensions
        WHERE hypertable_name = 'device_telemetry_logs'
        LIMIT 1"
    );

    $compressionSettings = DB::selectOne(
        "SELECT segmentby, orderby
        FROM timescaledb_information.hypertable_compression_settings
        WHERE hypertable::text = 'device_telemetry_logs'
        LIMIT 1"
    );

    $jobs = collect(DB::select(
        "SELECT proc_name, config
        FROM timescaledb_information.jobs
        WHERE hypertable_name = 'device_telemetry_logs'
        ORDER BY proc_name"
    ));

    expect($dimension?->time_interval)->toBe('1 day')
        ->and($compressionSettings?->segmentby)->toBe('device_id,schema_version_topic_id')
        ->and($compressionSettings?->orderby)->toContain('recorded_at DESC')
        ->and($jobs)->toHaveCount(2)
        ->and($jobs->pluck('proc_name')->all())->toContain('policy_compression', 'policy_retention')
        ->and(json_encode($jobs->pluck('config')->all()))->toContain('7 days')
        ->and(json_encode($jobs->pluck('config')->all()))->toContain('90 days');
});

it('schedules storage lifecycle application daily', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains($event->command, 'ingestion:apply-storage-lifecycle'));

    expect($event)->not->toBeNull()
        ->and($event?->expression)->toBe('0 0 * * *');
});

it('adds the composite telemetry index used by dashboard snapshot queries', function (): void {
    $index = DB::getDriverName() === 'pgsql'
        ? DB::selectOne(
            "SELECT indexname
            FROM pg_indexes
            WHERE schemaname = 'public'
              AND tablename = 'device_telemetry_logs'
              AND indexname = 'device_telemetry_logs_device_topic_recorded_index'
            LIMIT 1"
        )
        : DB::selectOne(
            "SELECT name AS indexname
            FROM sqlite_master
            WHERE type = 'index'
              AND tbl_name = 'device_telemetry_logs'
              AND name = 'device_telemetry_logs_device_topic_recorded_index'
            LIMIT 1"
        );

    expect($index)->not->toBeNull();
});
