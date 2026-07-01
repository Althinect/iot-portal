<?php

declare(strict_types=1);

namespace App\Events;

use App\Domain\Telemetry\Models\DeviceTelemetryLog;

class TelemetryReceived
{
    public string $telemetryLogId;

    public function __construct(DeviceTelemetryLog|string $telemetryLog)
    {
        $this->telemetryLogId = $telemetryLog instanceof DeviceTelemetryLog
            ? (string) $telemetryLog->getKey()
            : $telemetryLog;

    }

    /**
     * @param  array<int, string>  $with
     */
    public function telemetryLog(array $with = []): ?DeviceTelemetryLog
    {
        if (trim($this->telemetryLogId) === '') {
            return null;
        }

        return DeviceTelemetryLog::query()
            ->with($with)
            ->whereKey($this->telemetryLogId)
            ->first();
    }
}
