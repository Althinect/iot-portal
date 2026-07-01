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
        Schema::create('ingestion_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('device_profile_version_id')->nullable()->constrained('device_profile_versions')->nullOnDelete();
            $table->foreignId('device_channel_id')->nullable()->constrained('device_channels')->nullOnDelete();
            $table->string('source_subject', 255);
            $table->string('source_protocol', 50)->default('mqtt');
            $table->string('source_message_id', 255)->nullable();
            $table->string('source_deduplication_key', 64)->unique();
            $table->jsonb('raw_payload');
            $table->jsonb('error_summary')->nullable();
            $table->string('status', 50);
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status'], 'ingestion_messages_org_status_index');
            $table->index(['device_id', 'device_channel_id'], 'ingestion_messages_device_channel_index');
            $table->index('received_at', 'ingestion_messages_received_at_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ingestion_messages');
    }
};
