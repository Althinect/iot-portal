<?php

declare(strict_types=1);

namespace App\Domain\IoTDashboard\Application;

use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;

final class RealtimeStreamChannel
{
    public static function forDeviceTopic(string $deviceUuid, int $topicId): ?string
    {
        $resolvedDeviceUuid = trim($deviceUuid);

        if ($resolvedDeviceUuid === '' || $topicId < 1) {
            return null;
        }

        return "iot-dashboard.device.{$resolvedDeviceUuid}.topic.{$topicId}";
    }

    public static function forTelemetryLog(DeviceTelemetryLog $telemetryLog): ?string
    {
        $telemetryLog->loadMissing('device:id,uuid');

        $deviceUuid = is_string($telemetryLog->device?->uuid)
            ? $telemetryLog->device->uuid
            : '';
        $topicId = is_numeric($telemetryLog->schema_version_topic_id)
            ? (int) $telemetryLog->schema_version_topic_id
            : 0;

        return self::forDeviceTopic($deviceUuid, $topicId);
    }

    public static function forWidget(IoTDashboardWidget $widget): ?string
    {
        $widget->loadMissing('device:id,uuid');

        $deviceUuid = is_string($widget->device?->uuid)
            ? $widget->device->uuid
            : '';
        $topicId = (int) $widget->schema_version_topic_id;

        return self::forDeviceTopic($deviceUuid, $topicId);
    }
}
