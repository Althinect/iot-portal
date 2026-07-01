<?php

declare(strict_types=1);

namespace App\Domain\Alerts\Listeners;

use App\Domain\Alerts\Models\ThresholdPolicy;
use App\Domain\Alerts\Services\AlertIncidentManager;
use App\Domain\Automation\Enums\AutomationWorkflowStatus;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Services\JsonLogicEvaluator;
use App\Events\TelemetryReceived;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;

class QueueTelemetryThresholdAlertRecords implements ShouldQueue
{
    public function __construct(
        private readonly AlertIncidentManager $alertIncidentManager,
        private readonly JsonLogicEvaluator $jsonLogicEvaluator,
    ) {}

    public function shouldQueue(TelemetryReceived $event): bool
    {
        return $this->candidatePolicies($event)->isNotEmpty();
    }

    public function handle(TelemetryReceived $event): void
    {
        $telemetryLog = $event->telemetryLog;
        $payload = $this->normalizePayload($telemetryLog->getAttribute('transformed_values'));

        if ($payload === null) {
            return;
        }

        $this->candidatePolicies($event)->each(function (ThresholdPolicy $policy) use ($payload, $telemetryLog): void {
            $parameter = $policy->profileParameterDefinition();
            $value = $parameter?->extractValue($payload);

            if (! is_numeric($value)) {
                return;
            }

            $numericValue = (float) $value;
            $isBreached = (bool) $this->jsonLogicEvaluator->evaluate(
                $policy->resolvedConditionJsonLogic(),
                $this->buildEvaluationData($payload, $numericValue),
            );

            if ($isBreached) {
                $this->alertIncidentManager->openThresholdAlert($policy, $telemetryLog);

                return;
            }

            $this->alertIncidentManager->normalizeThresholdAlert($policy, $telemetryLog);
        });
    }

    /**
     * @return Collection<int, ThresholdPolicy>
     */
    private function candidatePolicies(TelemetryReceived $event): Collection
    {
        $telemetryLog = $event->telemetryLog;
        $device = $telemetryLog->device;

        if (! $device instanceof Device) {
            return collect();
        }

        return ThresholdPolicy::query()
            ->with(['deviceChannel', 'managedWorkflow'])
            ->where('organization_id', (int) $device->organization_id)
            ->where('device_id', (int) $device->id)
            ->where('device_channel_id', (int) $telemetryLog->device_channel_id)
            ->where('is_active', true)
            ->get()
            ->filter(function (ThresholdPolicy $policy): bool {
                return $policy->managedWorkflow?->status !== AutomationWorkflowStatus::Active->value;
            })
            ->values();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function buildEvaluationData(array $payload, float $value): array
    {
        $evaluationData = [
            'trigger' => ['value' => $value],
            'query' => ['value' => $value],
            'queries' => [],
            'payload' => $payload,
        ];

        return array_merge($payload, $evaluationData);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizePayload(mixed $payload): ?array
    {
        if (! is_array($payload)) {
            return null;
        }

        $normalizedPayload = [];

        foreach ($payload as $key => $value) {
            $normalizedPayload[(string) $key] = $value;
        }

        return $normalizedPayload;
    }
}
