<?php

declare(strict_types=1);

namespace App\Console\Commands\IoT;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Services\DevicePresenceService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class CheckDeviceHealth extends Command
{
    protected $signature = 'iot:check-device-health
                            {--seconds= : Heartbeat timeout threshold in seconds}';

    protected $description = 'Mark devices as offline if they have not sent telemetry within the heartbeat timeout';

    public function handle(DevicePresenceService $presenceService): int
    {
        $secondsOption = $this->option('seconds');
        $configuredTimeout = config('iot.presence.heartbeat_timeout_seconds', 300);
        $fallbackTimeout = is_numeric($configuredTimeout) ? (int) $configuredTimeout : 300;
        $seconds = is_numeric($secondsOption) ? (int) $secondsOption : $fallbackTimeout;

        $resolvedSeconds = max(1, $seconds);
        $now = now();
        $timeoutCutoff = $now->copy()->subSeconds($resolvedSeconds);
        $markedOffline = 0;
        $evaluatedCount = Device::query()
            ->where('connection_state', 'online')
            ->count();

        Device::query()
            ->where('connection_state', 'online')
            ->where(function (Builder $query) use ($now, $timeoutCutoff): void {
                $query->where('offline_deadline_at', '<=', $now)
                    ->orWhereNull('last_seen_at');

                $query->orWhere(function (Builder $query) use ($timeoutCutoff): void {
                    $query->whereNull('offline_deadline_at')
                        ->where('last_seen_at', '<=', $timeoutCutoff);
                });
            })
            ->orderBy('id')
            ->chunkById(100, function ($devices) use (&$markedOffline, $presenceService): void {
                foreach ($devices as $device) {
                    $presenceService->markOffline($device);
                    $markedOffline++;
                }
            });

        Log::channel('device_control')->info('Device health check completed', [
            'evaluated_device_count' => $evaluatedCount,
            'marked_offline_count' => $markedOffline,
            'checked_at' => $now->toIso8601String(),
            'fallback_timeout_seconds' => $resolvedSeconds,
            'fallback_timeout_cutoff' => $timeoutCutoff->toIso8601String(),
        ]);

        $this->info("Marked {$markedOffline} device(s) offline (evaluated: {$evaluatedCount}, heartbeat timeout: {$resolvedSeconds}s, cutoff: {$timeoutCutoff->toIso8601String()}).");

        return self::SUCCESS;
    }
}
