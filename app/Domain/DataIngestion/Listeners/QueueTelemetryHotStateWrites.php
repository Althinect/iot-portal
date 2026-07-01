<?php

declare(strict_types=1);

namespace App\Domain\DataIngestion\Listeners;

use App\Domain\DataIngestion\Concerns\InteractsWithTelemetrySideEffectsQueue;
use App\Domain\DataIngestion\Contracts\HotStateStore;
use App\Domain\DataIngestion\Models\IngestionMessage;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\Shared\Services\RuntimeSettingManager;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use App\Events\TelemetryReceived;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class QueueTelemetryHotStateWrites implements ShouldQueue
{
    use InteractsWithQueue;
    use InteractsWithTelemetrySideEffectsQueue;

    public function __construct(
        private readonly HotStateStore $hotStateStore,
        private readonly RuntimeSettingManager $runtimeSettings,
    ) {}

    public function shouldQueue(TelemetryReceived $event): bool
    {
        $telemetryLog = $event->telemetryLog([
            'device:id,organization_id',
            'channel:id',
        ]);

        if (
            ! $telemetryLog instanceof DeviceTelemetryLog
            || ! $telemetryLog->device instanceof Device
            || ! $telemetryLog->channel instanceof DeviceChannel
        ) {
            return false;
        }

        $coalesceSeconds = $this->coalesceSeconds($telemetryLog);

        if ($coalesceSeconds <= 0) {
            return true;
        }

        Cache::put(
            $this->latestTelemetryLogKey($telemetryLog),
            $event->telemetryLogId,
            $this->coalescingCacheTtlSeconds($coalesceSeconds),
        );

        return Cache::add(
            $this->coalescingGateKey($telemetryLog),
            true,
            $this->coalescingCacheTtlSeconds($coalesceSeconds),
        );
    }

    public function withDelay(TelemetryReceived $event): int
    {
        $telemetryLog = $event->telemetryLog(['device:id,organization_id']);

        if (! $telemetryLog instanceof DeviceTelemetryLog || ! $telemetryLog->device instanceof Device) {
            return 0;
        }

        return $this->coalesceSeconds($telemetryLog);
    }

    public function handle(TelemetryReceived $event): void
    {
        $telemetryLog = $this->telemetryLogForWrite($event);

        if (
            $telemetryLog === null
            || $telemetryLog->processing_state !== 'processed'
            || ! $telemetryLog->device instanceof Device
            || ! $telemetryLog->channel instanceof DeviceChannel
            || ! $telemetryLog->ingestionMessage instanceof IngestionMessage
        ) {
            return;
        }

        $device = $telemetryLog->device;
        $channel = $telemetryLog->channel;
        $ingestionMessage = $telemetryLog->ingestionMessage;
        $finalValues = $telemetryLog->getAttribute('transformed_values');

        if (! is_array($finalValues)) {
            return;
        }

        try {
            $this->hotStateStore->store($device, $channel, $finalValues, $ingestionMessage, $telemetryLog);
        } catch (Throwable $exception) {
            if (! $this->shouldSkipTransientHotStateFailure($exception)) {
                throw $exception;
            }

            Log::channel('device_control')->warning('Telemetry hot-state write skipped after NATS timeout.', [
                'device_uuid' => $device->uuid,
                'channel_key' => $channel->key,
                'telemetry_log_id' => $telemetryLog->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function viaConnection(): string
    {
        return $this->resolveTelemetrySideEffectsConnection();
    }

    public function viaQueue(): string
    {
        return $this->resolveTelemetrySideEffectsQueue();
    }

    private function telemetryLogForWrite(TelemetryReceived $event): ?DeviceTelemetryLog
    {
        $telemetryLog = $event->telemetryLog($this->writeRelations());

        if (! $telemetryLog instanceof DeviceTelemetryLog) {
            return null;
        }

        $coalesceSeconds = $this->coalesceSeconds($telemetryLog);

        if ($coalesceSeconds <= 0) {
            return $telemetryLog;
        }

        Cache::forget($this->coalescingGateKey($telemetryLog));

        $latestTelemetryLogId = Cache::get($this->latestTelemetryLogKey($telemetryLog));

        if (! is_string($latestTelemetryLogId) || trim($latestTelemetryLogId) === '') {
            return $telemetryLog;
        }

        $latestTelemetryLog = (new TelemetryReceived($latestTelemetryLogId))->telemetryLog($this->writeRelations());

        if (
            ! $latestTelemetryLog instanceof DeviceTelemetryLog
            || (string) $latestTelemetryLog->device_id !== (string) $telemetryLog->device_id
            || (string) $latestTelemetryLog->device_channel_id !== (string) $telemetryLog->device_channel_id
        ) {
            return $telemetryLog;
        }

        return $latestTelemetryLog;
    }

    /**
     * @return array<int, string>
     */
    private function writeRelations(): array
    {
        return [
            'device:id,uuid,external_id,organization_id',
            'channel:id,device_profile_version_id,key,address',
            'ingestionMessage:id,status,received_at',
        ];
    }

    private function coalesceSeconds(DeviceTelemetryLog $telemetryLog): int
    {
        $organizationId = $telemetryLog->device instanceof Device
            ? (int) $telemetryLog->device->organization_id
            : null;

        return $this->runtimeSettings->intValue('ingestion.pipeline.hot_state_coalesce_seconds', $organizationId);
    }

    private function coalescingCacheTtlSeconds(int $coalesceSeconds): int
    {
        return max(60, $coalesceSeconds + 30);
    }

    private function latestTelemetryLogKey(DeviceTelemetryLog $telemetryLog): string
    {
        return implode(':', [
            'telemetry-hot-state-write',
            'latest',
            (string) $telemetryLog->device_id,
            (string) $telemetryLog->device_channel_id,
        ]);
    }

    private function coalescingGateKey(DeviceTelemetryLog $telemetryLog): string
    {
        return implode(':', [
            'telemetry-hot-state-write',
            'gate',
            (string) $telemetryLog->device_id,
            (string) $telemetryLog->device_channel_id,
        ]);
    }

    private function shouldSkipTransientHotStateFailure(Throwable $exception): bool
    {
        if (! $this->isTransientNatsTimeout($exception)) {
            return false;
        }

        $delaySeconds = (int) config('device-control.feedback.transient_timeout_retry_delay_seconds', 0);

        if ($delaySeconds > 0 && method_exists($this, 'release')) {
            $this->release($delaySeconds);
        }

        return true;
    }

    private function isTransientNatsTimeout(Throwable $exception): bool
    {
        return str_contains(strtolower($exception->getMessage()), 'socket timed out');
    }
}
