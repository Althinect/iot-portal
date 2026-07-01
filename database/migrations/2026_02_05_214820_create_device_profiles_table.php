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
        Schema::create('device_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('key', 100)->index();
            $table->string('name');
            $table->jsonb('tags')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'key'], 'device_profiles_org_key_unique');
        });

        DB::statement('CREATE UNIQUE INDEX device_profiles_global_key_unique ON device_profiles (key) WHERE organization_id IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS device_profiles_global_key_unique');
        Schema::dropIfExists('device_profiles');
    }
};
