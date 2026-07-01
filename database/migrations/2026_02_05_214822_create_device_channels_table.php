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
        Schema::create('device_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_profile_version_id')->constrained('device_profile_versions')->cascadeOnDelete();
            $table->string('key', 100);
            $table->string('label', 255);
            $table->string('direction', 50);
            $table->string('purpose', 50)->nullable();
            $table->string('transport', 50)->default('mqtt');
            $table->string('address', 255);
            $table->string('http_method', 10)->default('');
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('qos')->default(1);
            $table->boolean('retain')->default(false);
            $table->unsignedInteger('sequence')->default(0);
            $table->jsonb('options')->nullable();
            $table->timestamps();

            $table->unique(['device_profile_version_id', 'key'], 'device_channels_version_key_unique');
            $table->unique(
                ['device_profile_version_id', 'transport', 'address', 'http_method'],
                'device_channels_version_transport_address_method_unique'
            );
            $table->index(['direction', 'purpose'], 'device_channels_direction_purpose_index');
            $table->index(['transport', 'direction'], 'device_channels_transport_direction_index');
        });

        Schema::create('device_channel_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_device_channel_id')->constrained('device_channels')->cascadeOnDelete();
            $table->foreignId('to_device_channel_id')->constrained('device_channels')->cascadeOnDelete();
            $table->string('link_type', 50);
            $table->timestamps();

            $table->unique(
                ['from_device_channel_id', 'to_device_channel_id', 'link_type'],
                'device_channel_links_unique'
            );
            $table->index('from_device_channel_id', 'device_channel_links_from_index');
            $table->index('to_device_channel_id', 'device_channel_links_to_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("
                ALTER TABLE device_channels
                ADD CONSTRAINT device_channels_purpose_check
                CHECK (purpose IS NULL OR purpose IN ('command', 'state', 'telemetry', 'event', 'ack'))
            ");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE device_channels DROP CONSTRAINT IF EXISTS device_channels_purpose_check');
        }

        Schema::dropIfExists('device_channel_links');
        Schema::dropIfExists('device_channels');
    }
};
