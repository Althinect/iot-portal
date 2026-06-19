<?php

declare(strict_types=1);

namespace App\Domain\DataIngestion\Listeners;

use App\Domain\DataIngestion\Concerns\InteractsWithTelemetrySideEffectsQueue;
use App\Domain\DataIngestion\Contracts\HotStateStore;
use App\Domain\DataIngestion\Models\IngestionMessage;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Events\TelemetryReceived;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class QueueTelemetryHotStateWrites implements ShouldQueue
{
    use InteractsWithQueue;
    use InteractsWithTelemetrySideEffectsQueue;

    public function __construct(
        private readonly HotStateStore $hotStateStore,
    ) {}

    public function handle(TelemetryReceived $event): void
    {
        $telemetryLog = $event->telemetryLog->loadMissing([
            'device:id,uuid,external_id,device_type_id',
            'topic:id,device_schema_version_id,key,suffix',
            'ingestionMessage:id,status',
        ]);

        if (
            $telemetryLog->processing_state !== 'processed'
            || ! $telemetryLog->device instanceof Device
            || ! $telemetryLog->topic instanceof SchemaVersionTopic
            || ! $telemetryLog->ingestionMessage instanceof IngestionMessage
        ) {
            return;
        }

        $device = $telemetryLog->device;
        $topic = $telemetryLog->topic;
        $ingestionMessage = $telemetryLog->ingestionMessage;
        $finalValues = $telemetryLog->getAttribute('transformed_values');

        if (! is_array($finalValues)) {
            return;
        }

        /** @var array<string, mixed> $finalValues */
        try {
            $this->hotStateStore->store($device, $topic, $finalValues, $ingestionMessage, $telemetryLog);
        } catch (Throwable $exception) {
            if (! $this->shouldSkipTransientHotStateFailure($exception)) {
                throw $exception;
            }

            Log::channel('device_control')->warning('Telemetry hot-state write skipped after NATS timeout.', [
                'device_uuid' => $device->uuid,
                'topic_key' => $topic->key,
                'ingestion_message_id' => $ingestionMessage->id,
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

    private function shouldSkipTransientHotStateFailure(Throwable $exception): bool
    {
        return str_contains($exception->getMessage(), 'Processing timeout');
    }
}
