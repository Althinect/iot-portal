<?php

declare(strict_types=1);

namespace App\Domain\DataIngestion\Services;

use App\Domain\DataIngestion\Contracts\AnalyticsPublisher;
use App\Domain\DataIngestion\Models\IngestionMessage;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Domain\Shared\Services\RuntimeSettingManager;

class TelemetryAnalyticsPublishService
{
    public function __construct(
        private readonly AnalyticsPublisher $publisher,
        private readonly RuntimeSettingManager $runtimeSettingManager,
    ) {}

    /**
     * @param  array<string, mixed>  $finalValues
     */
    public function publishTelemetry(Device $device, SchemaVersionTopic $topic, array $finalValues, IngestionMessage $ingestionMessage): void
    {
        if (! $this->runtimeSettingManager->booleanValue('ingestion.pipeline.publish_analytics', $device->organization_id)) {
            return;
        }

        $this->publisher->publishTelemetry($device, $topic, $finalValues, $ingestionMessage);
    }

    /**
     * @param  array<string, mixed>  $validationErrors
     */
    public function publishInvalid(Device $device, SchemaVersionTopic $topic, array $validationErrors, IngestionMessage $ingestionMessage): void
    {
        if (! (bool) config('ingestion.publish_invalid_events', true)) {
            return;
        }

        $this->publisher->publishInvalid($device, $topic, $validationErrors, $ingestionMessage);
    }
}
