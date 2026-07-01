<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_twins', function (Blueprint $table) {
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->jsonb('tags')->nullable();
            $table->jsonb('desired')->nullable();
            $table->jsonb('reported')->nullable();
            $table->string('etag', 64)->nullable();
            $table->unsignedInteger('desired_version')->default(0);
            $table->unsignedInteger('reported_version')->default(0);
            $table->timestamp('desired_updated_at')->nullable();
            $table->timestamp('reported_updated_at')->nullable();
            $table->timestamps();

            $table->primary('device_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_twins');
    }
};
