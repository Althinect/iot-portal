<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->unsignedInteger('presence_timeout_seconds')->nullable();
            $table->timestamp('offline_deadline_at')->nullable()->index();
        });

        $defaultTimeout = config('iot.presence.heartbeat_timeout_seconds', 300);
        $timeoutSeconds = is_numeric($defaultTimeout) && (int) $defaultTimeout > 0
            ? (int) $defaultTimeout
            : 300;

        DB::table('devices')
            ->select(['id', 'last_seen_at'])
            ->where('connection_state', 'online')
            ->whereNotNull('last_seen_at')
            ->orderBy('id')
            ->chunkById(100, function ($devices) use ($timeoutSeconds): void {
                foreach ($devices as $device) {
                    if ($device->last_seen_at === null) {
                        continue;
                    }

                    $lastSeenAt = Carbon::parse((string) $device->last_seen_at);

                    DB::table('devices')
                        ->where('id', $device->id)
                        ->update([
                            'offline_deadline_at' => $lastSeenAt->copy()->addSeconds($timeoutSeconds),
                        ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropIndex(['offline_deadline_at']);
            $table->dropColumn([
                'presence_timeout_seconds',
                'offline_deadline_at',
            ]);
        });
    }
};
