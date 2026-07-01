<?php

declare(strict_types=1);

namespace App\Domain\IoTDashboard\Application;

use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;

final class RealtimeStreamChannel
{
    public static function forDeviceChannel(string $deviceUuid, int $channelId): ?string
    {
        $resolvedDeviceUuid = trim($deviceUuid);

        if ($resolvedDeviceUuid === '' || $channelId < 1) {
            return null;
        }

        return "iot-dashboard.device.{$resolvedDeviceUuid}.channel.{$channelId}";
    }

    public static function forTelemetryLog(DeviceTelemetryLog $telemetryLog): ?string
    {
        $telemetryLog->loadMissing('device:id,uuid');

        $deviceUuid = is_string($telemetryLog->device?->uuid)
            ? $telemetryLog->device->uuid
            : '';
        $channelId = is_numeric($telemetryLog->device_channel_id)
            ? (int) $telemetryLog->device_channel_id
            : 0;

        return self::forDeviceChannel($deviceUuid, $channelId);
    }

    public static function forWidget(IoTDashboardWidget $widget): ?string
    {
        $widget->loadMissing('device:id,uuid');

        $deviceUuid = is_string($widget->device?->uuid)
            ? $widget->device->uuid
            : '';
        $channelId = (int) $widget->device_channel_id;

        return self::forDeviceChannel($deviceUuid, $channelId);
    }
}
