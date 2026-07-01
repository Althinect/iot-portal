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
        Schema::create('iot_dashboard_widgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('iot_dashboard_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_channel_id')->nullable()->constrained('device_channels')->nullOnDelete();
            $table->string('type', 50)->default('line_chart');
            $table->string('title', 255);
            $table->jsonb('series_config');
            $table->jsonb('options')->nullable();
            $table->boolean('use_websocket')->default(true);
            $table->boolean('use_polling')->default(true);
            $table->unsignedInteger('polling_interval_seconds')->default(10);
            $table->unsignedInteger('lookback_minutes')->default(120);
            $table->unsignedInteger('max_points')->default(240);
            $table->unsignedInteger('sequence')->default(0);
            $table->timestamps();

            $table->index(['iot_dashboard_id', 'sequence'], 'iot_dashboard_widgets_dashboard_sequence_index');
            $table->index('device_channel_id', 'iot_dashboard_widgets_channel_index');
            $table->index(['iot_dashboard_id', 'type'], 'iot_dashboard_widgets_dashboard_type_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('iot_dashboard_widgets');
    }
};
