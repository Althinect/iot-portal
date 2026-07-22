<?php

declare(strict_types=1);

namespace App\Domain\Automation\Services;

use App\Domain\Automation\Enums\AutomationWorkflowStatus;
use App\Domain\Automation\Models\AutomationTelemetryTrigger;

class AutomationTelemetryScopeIndex
{
    private const REFRESH_INTERVAL_SECONDS = 15;

    private int $loadedVersion = 0;

    private float $loadedAt = 0.0;

    /** @var array<int, array{device_id: ?int, device_channel_id: ?int}> */
    private array $scopes = [];

    public function __construct(
        private readonly AutomationTriggerCacheInvalidator $cacheInvalidator,
    ) {}

    public function hasCandidate(int $deviceId, int $deviceChannelId): bool
    {
        $this->refreshWhenStale();

        foreach ($this->scopes as $scope) {
            $deviceMatches = $scope['device_id'] === null || $scope['device_id'] === $deviceId;
            $channelMatches = $scope['device_channel_id'] === null || $scope['device_channel_id'] === $deviceChannelId;

            if ($deviceMatches && $channelMatches) {
                return true;
            }
        }

        return false;
    }

    private function refreshWhenStale(): void
    {
        $currentVersion = $this->cacheInvalidator->currentVersion();

        if (
            $this->loadedVersion === $currentVersion
            && $this->loadedAt > 0
            && (microtime(true) - $this->loadedAt) < self::REFRESH_INTERVAL_SECONDS
        ) {
            return;
        }

        $this->scopes = AutomationTelemetryTrigger::query()
            ->join('automation_workflow_versions', 'automation_workflow_versions.id', '=', 'automation_telemetry_triggers.workflow_version_id')
            ->join('automation_workflows', 'automation_workflows.id', '=', 'automation_workflow_versions.automation_workflow_id')
            ->where('automation_workflows.status', AutomationWorkflowStatus::Active->value)
            ->whereColumn('automation_workflows.active_version_id', 'automation_workflow_versions.id')
            ->get([
                'automation_telemetry_triggers.device_id',
                'automation_telemetry_triggers.device_channel_id',
            ])
            ->map(static function (AutomationTelemetryTrigger $trigger): array {
                return [
                    'device_id' => is_numeric($trigger->device_id) ? (int) $trigger->device_id : null,
                    'device_channel_id' => is_numeric($trigger->device_channel_id) ? (int) $trigger->device_channel_id : null,
                ];
            })
            ->unique(static fn (array $scope): string => ($scope['device_id'] ?? '*').':'.($scope['device_channel_id'] ?? '*'))
            ->values()
            ->all();
        $this->loadedVersion = $currentVersion;
        $this->loadedAt = microtime(true);
    }
}
