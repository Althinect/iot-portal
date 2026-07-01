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
        Schema::create('device_profile_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_profile_id')->constrained('device_profiles')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 50)->default('draft');
            $table->string('protocol', 50)->default('mqtt');
            $table->jsonb('protocol_config')->nullable();
            $table->longText('firmware_template')->nullable();
            $table->jsonb('ingestion_config')->nullable();
            $table->jsonb('virtual_standard_profile')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['device_profile_id', 'version'], 'device_profile_versions_profile_version_unique');
            $table->index(['device_profile_id', 'status'], 'device_profile_versions_profile_status_index');
        });

        DB::statement("CREATE UNIQUE INDEX device_profile_versions_active_unique ON device_profile_versions (device_profile_id) WHERE status = 'active'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS device_profile_versions_active_unique');
        Schema::dropIfExists('device_profile_versions');
    }
};
