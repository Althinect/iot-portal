<?php

declare(strict_types=1);

namespace App\Domain\DataIngestion\Contracts;

use App\Domain\DataIngestion\Models\IngestionMessage;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;

interface HotStateStore
{
    /**
     * @param  array<string, mixed>  $finalValues
     */
    public function store(
        Device $device,
        DeviceChannel $channel,
        array $finalValues,
        IngestionMessage $ingestionMessage,
        ?DeviceTelemetryLog $telemetryLog = null,
    ): void;
}
