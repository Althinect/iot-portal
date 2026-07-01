<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_parameter_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_channel_id')->constrained('device_channels')->cascadeOnDelete();
            $table->string('key', 100);
            $table->string('label', 255);
            $table->string('json_path', 255);
            $table->string('type', 50);
            $table->string('category', 50)->default('measurement');
            $table->string('unit', 50)->nullable();
            $table->boolean('required')->default(false);
            $table->boolean('is_critical')->default(false);
            $table->jsonb('validation_rules')->nullable();
            $table->jsonb('control_ui')->nullable();
            $table->string('validation_error_code', 100)->nullable();
            $table->jsonb('mutation_expression')->nullable();
            $table->unsignedInteger('sequence')->default(0);
            $table->boolean('is_active')->default(true);
            $table->jsonb('default_value')->nullable();
            $table->timestamps();

            $table->unique(['device_channel_id', 'key'], 'profile_parameter_definitions_channel_key_unique');
            $table->index(['device_channel_id', 'is_active'], 'profile_parameter_definitions_channel_active_index');
        });

        Schema::create('profile_derived_parameter_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_profile_version_id')->constrained('device_profile_versions')->cascadeOnDelete();
            $table->string('key', 100);
            $table->string('label', 255);
            $table->string('data_type', 50);
            $table->string('unit', 50)->nullable();
            $table->jsonb('expression');
            $table->jsonb('dependencies')->nullable();
            $table->string('json_path', 255)->nullable();
            $table->timestamps();

            $table->unique(['device_profile_version_id', 'key'], 'profile_derived_param_defs_version_key_unique');
            $table->index(['device_profile_version_id', 'data_type'], 'profile_derived_param_defs_version_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_derived_parameter_definitions');
        Schema::dropIfExists('profile_parameter_definitions');
    }
};
