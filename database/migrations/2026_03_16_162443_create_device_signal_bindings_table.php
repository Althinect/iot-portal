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
        Schema::create('device_signal_bindings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parameter_definition_id')->constrained('parameter_definitions')->cascadeOnDelete();
            $table->string('source_topic');
            $table->string('source_json_path');
            $table->string('source_adapter', 100)->nullable();
            $table->unsignedInteger('sequence')->default(0);
            $table->boolean('is_active')->default(true);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['source_topic', 'is_active'], 'device_signal_bindings_source_topic_active_index');
            $table->unique(['device_id', 'parameter_definition_id'], 'device_signal_bindings_device_parameter_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_signal_bindings');
    }
};
