<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('threshold_policy_id')->constrained('threshold_policies')->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_channel_id')->constrained('device_channels')->cascadeOnDelete();
            $table->string('parameter_key');
            $table->timestampTz('alerted_at');
            $table->uuid('alerted_telemetry_log_id')->nullable();
            $table->timestampTz('normalized_at')->nullable();
            $table->uuid('normalized_telemetry_log_id')->nullable();
            $table->timestampTz('alert_notification_sent_at')->nullable();
            $table->timestampTz('normalized_notification_sent_at')->nullable();
            $table->timestamps();

            $table->index(['threshold_policy_id', 'normalized_at']);
            $table->index(['device_id', 'device_channel_id', 'parameter_key', 'normalized_at'], 'alerts_device_channel_parameter_normalized_idx');
            $table->index('alerted_telemetry_log_id');
            $table->index('normalized_telemetry_log_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX alerts_open_threshold_policy_unique ON alerts (threshold_policy_id) WHERE normalized_at IS NULL'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS alerts_open_threshold_policy_unique');
        }

        Schema::dropIfExists('alerts');
    }
};
