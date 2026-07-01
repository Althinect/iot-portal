<?php

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
        Schema::create('device_desired_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->unique()->constrained()->cascadeOnDelete();
            $table->jsonb('desired_state');
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('device_desired_channel_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_channel_id')
                ->constrained('device_channels')
                ->cascadeOnDelete();
            $table->jsonb('desired_payload');
            $table->uuid('correlation_id')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'device_channel_id'], 'device_desired_channel_states_device_channel_unique');
            $table->index('correlation_id', 'device_desired_channel_states_correlation_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_desired_channel_states');
        Schema::dropIfExists('device_desired_states');
    }
};
