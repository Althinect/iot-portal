<?php

declare(strict_types=1);

namespace App\Domain\Automation\Services;

use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use Illuminate\Cache\CacheManager;

class TelemetryAutomationDispatchThrottle
{
    public function __construct(
        private readonly CacheManager $cacheManager,
    ) {}

    public function shouldDispatch(int $workflowVersionId, DeviceTelemetryLog $telemetryLog): bool
    {
        $cooldownSeconds = $this->cooldownSeconds();

        if ($cooldownSeconds <= 0) {
            return true;
        }

        return $this->cacheManager->store()->add(
            $this->cacheKey($workflowVersionId, $telemetryLog),
            now()->timestamp,
            now()->addSeconds($cooldownSeconds),
        );
    }

    private function cooldownSeconds(): int
    {
        $configuredCooldown = config('automation.telemetry_dispatch_cooldown_seconds', 0);

        if (! is_numeric($configuredCooldown)) {
            return 0;
        }

        return max(0, (int) $configuredCooldown);
    }

    private function cacheKey(int $workflowVersionId, DeviceTelemetryLog $telemetryLog): string
    {
        $organizationId = is_numeric($telemetryLog->device?->organization_id)
            ? (string) $telemetryLog->device->organization_id
            : 'global';
        $deviceId = $telemetryLog->device_id > 0 ? (string) $telemetryLog->device_id : 'unknown-device';
        $topicId = $telemetryLog->schema_version_topic_id > 0 ? (string) $telemetryLog->schema_version_topic_id : 'unknown-topic';

        return "automation:telemetry-dispatch:workflow:{$workflowVersionId}:org:{$organizationId}:device:{$deviceId}:topic:{$topicId}";
    }
}
