<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entities', function (Blueprint $table): void {
            $table->softDeletes();
        });

        Schema::table('devices', function (Blueprint $table): void {
            $table->foreignId('entity_id')
                ->nullable()
                ->after('organization_id')
                ->constrained('entities')
                ->nullOnDelete();
        });

        Schema::table('iot_dashboards', function (Blueprint $table): void {
            $table->foreignId('entity_id')
                ->nullable()
                ->after('organization_id')
                ->constrained('entities')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('iot_dashboards', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('entity_id');
        });

        Schema::table('devices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('entity_id');
        });

        Schema::table('entities', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
