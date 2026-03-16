<?php

declare(strict_types=1);

use App\Domain\IoTDashboard\Enums\DashboardHistoryPreset;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('iot_dashboards', function (Blueprint $table): void {
            $table->string('default_history_preset', 10)
                ->default(DashboardHistoryPreset::Last6Hours->value)
                ->after('refresh_interval_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('iot_dashboards', function (Blueprint $table): void {
            $table->dropColumn('default_history_preset');
        });
    }
};
