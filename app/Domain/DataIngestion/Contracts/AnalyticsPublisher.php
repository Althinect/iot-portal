<?php

declare(strict_types=1);

namespace App\Domain\DataIngestion\Contracts;

use App\Domain\DataIngestion\Models\IngestionMessage;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Models\DeviceChannel;

interface AnalyticsPublisher
{
    /**
     * @param  array<string, mixed>  $finalValues
     */
    public function publishTelemetry(Device $device, DeviceChannel $channel, array $finalValues, IngestionMessage $ingestionMessage): void;

    /**
     * @param  array<string, mixed>  $validationErrors
     */
    public function publishInvalid(Device $device, DeviceChannel $channel, array $validationErrors, IngestionMessage $ingestionMessage): void;
}
