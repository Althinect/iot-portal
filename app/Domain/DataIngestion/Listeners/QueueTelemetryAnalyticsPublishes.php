<?php

declare(strict_types=1);

namespace App\Domain\DataIngestion\Listeners;

use App\Domain\DataIngestion\Concerns\InteractsWithTelemetrySideEffectsQueue;
use App\Domain\DataIngestion\Models\IngestionMessage;
use App\Domain\DataIngestion\Services\TelemetryAnalyticsPublishService;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Events\TelemetryReceived;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class QueueTelemetryAnalyticsPublishes implements ShouldQueue
{
    use InteractsWithQueue;
    use InteractsWithTelemetrySideEffectsQueue;

    public function __construct(
        private readonly TelemetryAnalyticsPublishService $analyticsPublishService,
    ) {}

    public function handle(TelemetryReceived $event): void
    {
        $telemetryLog = $event->telemetryLog->loadMissing([
            'device:id,uuid,external_id,organization_id,is_active',
            'topic:id,device_schema_version_id,key,suffix',
            'ingestionMessage:id,status',
        ]);

        if (
            ! in_array($telemetryLog->processing_state, ['processed', 'invalid'], true)
            || ! $telemetryLog->device instanceof Device
            || ! $telemetryLog->topic instanceof SchemaVersionTopic
            || ! $telemetryLog->ingestionMessage instanceof IngestionMessage
        ) {
            return;
        }

        $device = $telemetryLog->device;
        $topic = $telemetryLog->topic;
        $ingestionMessage = $telemetryLog->ingestionMessage;

        if ($telemetryLog->processing_state === 'processed') {
            $finalValues = $telemetryLog->getAttribute('transformed_values');

            if (! is_array($finalValues)) {
                return;
            }

            /** @var array<string, mixed> $finalValues */
            $this->analyticsPublishService->publishTelemetry($device, $topic, $finalValues, $ingestionMessage);

            return;
        }

        if (! $device->is_active) {
            return;
        }

        $validationErrors = $telemetryLog->getAttribute('validation_errors');

        if (! is_array($validationErrors)) {
            return;
        }

        /** @var array<string, mixed> $validationErrors */
        $this->analyticsPublishService->publishInvalid($device, $topic, $validationErrors, $ingestionMessage);
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
