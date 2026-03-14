<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_telemetry_logs', function (Blueprint $table): void {
            $table->index(
                ['device_id', 'schema_version_topic_id', 'recorded_at'],
                'device_telemetry_logs_device_topic_recorded_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('device_telemetry_logs', function (Blueprint $table): void {
            $table->dropIndex('device_telemetry_logs_device_topic_recorded_index');
        });
    }
};
