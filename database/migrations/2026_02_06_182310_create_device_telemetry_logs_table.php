<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('device_telemetry_logs', function (Blueprint $table) {
            $table->uuid('id');
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_profile_version_id')->constrained('device_profile_versions');
            $table->foreignId('device_channel_id')->nullable()->constrained('device_channels')->nullOnDelete();
            $table->string('validation_status', 50);
            $table->string('processing_state', 50)->default('processed');
            $table->jsonb('raw_payload');
            $table->jsonb('validation_errors')->nullable();
            $table->jsonb('mutated_values')->nullable();
            $table->jsonb('transformed_values');
            $table->timestamp('recorded_at');
            $table->timestamp('received_at')->useCurrent();
            $table->timestamps();

            $table->primary(['id', 'recorded_at']);
            $table->index('recorded_at');
            $table->index('validation_status');
            $table->index('processing_state');
            $table->index(['device_id', 'device_channel_id', 'recorded_at'], 'device_telemetry_logs_device_channel_recorded_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS timescaledb');
            DB::statement("SELECT create_hypertable('device_telemetry_logs', 'recorded_at', if_not_exists => TRUE)");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_telemetry_logs');
    }
};
