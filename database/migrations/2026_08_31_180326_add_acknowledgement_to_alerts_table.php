<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table): void {
            $table->timestamp('acknowledged_at')->nullable()->after('normalized_at');
            $table->foreignId('acknowledged_by_user_id')
                ->nullable()
                ->after('acknowledged_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->text('acknowledgement_note')->nullable()->after('acknowledged_by_user_id');
            $table->index(['organization_id', 'acknowledged_at']);
        });
    }

    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'acknowledged_at']);
            $table->dropConstrainedForeignId('acknowledged_by_user_id');
            $table->dropColumn(['acknowledged_at', 'acknowledgement_note']);
        });
    }
};
