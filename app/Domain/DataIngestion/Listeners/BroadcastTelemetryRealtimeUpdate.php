<?php

declare(strict_types=1);

namespace App\Domain\DataIngestion\Listeners;

use App\Domain\DataIngestion\Concerns\InteractsWithTelemetrySideEffectsQueue;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\Shared\Services\RuntimeSettingManager;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use App\Events\TelemetryRealtimeUpdated;
use App\Events\TelemetryReceived;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

class BroadcastTelemetryRealtimeUpdate implements ShouldQueue
{
    use InteractsWithQueue;
    use InteractsWithTelemetrySideEffectsQueue;

    public function __construct(
        private readonly RuntimeSettingManager $runtimeSettings,
    ) {}

    public function shouldQueue(TelemetryReceived $event): bool
    {
        $telemetryLog = $event->telemetryLog(['device:id,organization_id']);

        if (! $telemetryLog instanceof DeviceTelemetryLog || ! $telemetryLog->device instanceof Device) {
            return false;
        }

        $organizationId = (int) $telemetryLog->device->organization_id;

        if (! $this->runtimeSettings->booleanValue('ingestion.pipeline.broadcast_realtime', $organizationId)) {
            return false;
        }

        $throttleSeconds = $this->runtimeSettings->intValue('ingestion.pipeline.broadcast_throttle_seconds', $organizationId);

        if ($throttleSeconds <= 0) {
            return true;
        }

        return Cache::add($this->throttleKey($telemetryLog), true, $throttleSeconds);
    }

    public function handle(TelemetryReceived $event): void
    {
        broadcast(new TelemetryRealtimeUpdated($event->telemetryLogId));
    }

    public function viaConnection(): string
    {
        return $this->resolveTelemetrySideEffectsConnection();
    }

    public function viaQueue(): string
    {
        return $this->resolveTelemetrySideEffectsQueue();
    }

    private function throttleKey(DeviceTelemetryLog $telemetryLog): string
    {
        return implode(':', [
            'telemetry-realtime-broadcast',
            (string) $telemetryLog->device_id,
            (string) $telemetryLog->device_channel_id,
        ]);
    }
}
