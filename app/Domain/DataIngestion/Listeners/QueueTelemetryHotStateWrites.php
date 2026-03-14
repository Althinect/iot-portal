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
        $this->hotStateStore->store($device, $topic, $finalValues, $ingestionMessage);
    }

    public function viaConnection(): string
    {
        return $this->resolveTelemetrySideEffectsConnection();
    }

    public function viaQueue(): string
    {
        return $this->resolveTelemetrySideEffectsQueue();
    }
}
