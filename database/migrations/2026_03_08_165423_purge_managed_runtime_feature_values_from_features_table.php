<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('features')
            ->whereIn('name', [
                'ingestion.pipeline.enabled',
                'ingestion.pipeline.driver',
                'ingestion.pipeline.broadcast_realtime',
                'ingestion.pipeline.publish_analytics',
                'automation.pipeline.telemetry_fanout',
                'iot.diagnostics.raw_telemetry_stream',
            ])
            ->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
