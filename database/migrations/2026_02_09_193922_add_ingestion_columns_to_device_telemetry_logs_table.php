<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('device_telemetry_logs', function (Blueprint $table) {
            $table->uuid('ingestion_message_id')->nullable()->after('device_channel_id');

            $table->foreign('ingestion_message_id')
                ->references('id')
                ->on('ingestion_messages')
                ->nullOnDelete();

            $table->index('ingestion_message_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('device_telemetry_logs', function (Blueprint $table) {
            $table->dropForeign(['ingestion_message_id']);
            $table->dropIndex(['ingestion_message_id']);
            $table->dropColumn(['ingestion_message_id']);
        });
    }
};
