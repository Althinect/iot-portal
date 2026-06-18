<?php

declare(strict_types=1);

namespace App\Domain\IoTDashboard\Widgets\ThresholdStatusCard;

use App\Domain\Alerts\Models\Alert;
use App\Domain\Alerts\Models\ThresholdPolicy;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceSchema\Models\ParameterDefinition;
use App\Domain\DeviceSchema\Services\JsonLogicEvaluator;
use App\Domain\IoTDashboard\Application\DashboardHistoryRange;
use App\Domain\IoTDashboard\Contracts\WidgetConfig;
use App\Domain\IoTDashboard\Contracts\WidgetSnapshotResolver;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use App\Domain\IoTDashboard\Widgets\Concerns\InterpretsThresholdStatusSnapshot;
use App\Domain\Telemetry\Services\TelemetryQueryService;
use App\Filament\Admin\Resources\AutomationThresholdPolicies\AutomationThresholdPolicyResource;
use InvalidArgumentException;

class ThresholdStatusCardSnapshotResolver implements WidgetSnapshotResolver
{
    use InterpretsThresholdStatusSnapshot;

    public function __construct(
        private readonly JsonLogicEvaluator $jsonLogicEvaluator,
        private readonly TelemetryQueryService $telemetryQuery,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function resolve(
        IoTDashboardWidget $widget,
        WidgetConfig $config,
        ?DashboardHistoryRange $historyRange = null,
    ): array {
        if (! $config instanceof ThresholdStatusCardConfig) {
            throw new InvalidArgumentException('Threshold status card widgets require ThresholdStatusCardConfig.');
        }

        $organizationId = $widget->dashboard()->value('organization_id');

        if (! is_numeric($organizationId) || $config->policyId() < 1) {
            return [
                'widget_id' => (int) $widget->id,
                'generated_at' => now()->toIso8601String(),
                'card' => null,
            ];
        }

        $policy = ThresholdPolicy::query()
            ->with(['device', 'parameterDefinition.topic'])
            ->where('organization_id', (int) $organizationId)
            ->find($config->policyId());

        if (! $policy instanceof ThresholdPolicy) {
            return [
                'widget_id' => (int) $widget->id,
                'generated_at' => now()->toIso8601String(),
                'card' => null,
            ];
        }

        $parameter = $policy->parameterDefinition;
        $device = $policy->device;
        $topicId = $parameter instanceof ParameterDefinition ? (int) $parameter->schema_version_topic_id : null;

        $latestLog = $topicId === null
            ? null
            : $this->telemetryQuery->latestLog((int) $policy->device_id, $topicId, $config->lookbackMinutes());
        $latestAvailableLog = $topicId === null
            ? null
            : $this->telemetryQuery->latestLog((int) $policy->device_id, $topicId);

        $payload = is_array($latestLog?->transformed_values) ? $latestLog->transformed_values : [];
        $value = $parameter?->extractValue($payload);
        $numericValue = is_numeric($value) ? (float) $value : null;
        $latestPayload = is_array($latestAvailableLog?->transformed_values) ? $latestAvailableLog->transformed_values : [];
        $latestValue = $parameter?->extractValue($latestPayload);
        $latestNumericValue = is_numeric($latestValue) ? (float) $latestValue : null;
        $connectionState = $device instanceof Device
            ? strtolower($device->effectiveConnectionState())
            : 'unknown';
        $status = $this->resolveStatus($policy, $connectionState, $payload, $numericValue);
        $thresholdState = $this->resolveThresholdState($policy, $latestPayload, $latestNumericValue);
        $unit = $this->resolveUnitSymbol($parameter?->unit);
        $valueSnapshot = $this->resolveThresholdValueSnapshot(
            status: $status,
            device: $device instanceof Device ? $device : null,
            latestRecentLog: $latestLog,
            latestAvailableLog: $latestAvailableLog,
            currentValue: $numericValue,
            lastValue: $latestNumericValue,
            unit: $unit,
        );
        $openAlert = Alert::query()
            ->where('threshold_policy_id', (int) $policy->id)
            ->whereNull('normalized_at')
            ->latest('alerted_at')
            ->latest('id')
            ->first();
        $openAlert = $openAlert instanceof Alert ? $openAlert : null;
        $alertTriggeredAt = $status === 'alert'
            ? $this->toIso8601String($openAlert?->alerted_at)
            : null;
        $thresholdBreachedAt = $thresholdState === 'alert'
            ? $this->toIso8601String($openAlert instanceof Alert ? $openAlert->alerted_at : $latestAvailableLog?->recorded_at)
            : null;

        return [
            'widget_id' => (int) $widget->id,
            'generated_at' => now()->toIso8601String(),
            'device_connection_state' => $device?->effectiveConnectionState(),
            'device_last_seen_at' => $this->toIso8601String($device?->lastSeenAt()),
            'card' => [
                'policy_id' => (int) $policy->id,
                'device_name' => $device instanceof Device ? $device->name : $policy->name,
                'connection_state' => strtoupper($connectionState),
                'last_telemetry_at' => $this->toIso8601String($latestLog?->recorded_at),
                'display_timestamp' => $valueSnapshot['display_timestamp'],
                'last_online_at' => $valueSnapshot['last_online_at'],
                'rule_label' => $policy->conditionLabel($parameter?->unit),
                'status' => $status,
                'status_label' => strtoupper(str_replace('_', ' ', $status)),
                'alert_triggered_at' => $alertTriggeredAt,
                'threshold_state' => $thresholdState,
                'threshold_state_label' => strtoupper(str_replace('_', ' ', $thresholdState)),
                'threshold_breached_at' => $thresholdBreachedAt,
                'current_value' => $valueSnapshot['display_value'],
                'current_value_display' => $valueSnapshot['display_value_display'],
                'current_value_recorded_at' => $valueSnapshot['current_value_recorded_at'],
                'last_value' => $valueSnapshot['last_value'],
                'last_value_display' => $valueSnapshot['last_value_display'],
                'last_value_recorded_at' => $valueSnapshot['last_value_recorded_at'],
                'edit_url' => AutomationThresholdPolicyResource::getUrl('edit', ['record' => $policy]),
                'is_active' => (bool) $policy->is_active,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveStatus(ThresholdPolicy $policy, string $connectionState, array $payload, ?float $value): string
    {
        if ($policy->is_active !== true) {
            return 'inactive';
        }

        if ($connectionState === 'offline') {
            return 'offline';
        }

        if ($value === null) {
            return 'no_data';
        }

        $isBreached = $this->jsonLogicEvaluator->evaluate(
            $policy->resolvedConditionJsonLogic(),
            $this->buildEvaluationData($payload, $value),
        );

        return $isBreached ? 'alert' : 'normal';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveThresholdState(ThresholdPolicy $policy, array $payload, ?float $value): string
    {
        if ($policy->is_active !== true) {
            return 'inactive';
        }

        if ($value === null) {
            return 'no_data';
        }

        $isBreached = $this->jsonLogicEvaluator->evaluate(
            $policy->resolvedConditionJsonLogic(),
            $this->buildEvaluationData($payload, $value),
        );

        return $isBreached ? 'alert' : 'normal';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function buildEvaluationData(array $payload, ?float $value): array
    {
        $evaluationData = [
            'trigger' => ['value' => $value],
            'query' => ['value' => $value],
            'queries' => [],
            'payload' => $payload,
        ];

        if ($payload !== []) {
            $evaluationData = array_merge($payload, $evaluationData);
        }

        return $evaluationData;
    }
}
