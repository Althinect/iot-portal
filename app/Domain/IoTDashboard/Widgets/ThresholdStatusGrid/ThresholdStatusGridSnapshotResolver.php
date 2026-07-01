<?php

declare(strict_types=1);

namespace App\Domain\IoTDashboard\Widgets\ThresholdStatusGrid;

use App\Domain\Alerts\Models\Alert;
use App\Domain\Alerts\Models\ThresholdPolicy;
use App\Domain\Alerts\Services\AlertIncidentManager;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use App\Domain\DeviceProfile\Services\JsonLogicEvaluator;
use App\Domain\IoTDashboard\Application\DashboardHistoryRange;
use App\Domain\IoTDashboard\Contracts\WidgetConfig;
use App\Domain\IoTDashboard\Contracts\WidgetSnapshotResolver;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use App\Domain\IoTDashboard\Widgets\Concerns\InterpretsThresholdStatusSnapshot;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use App\Domain\Telemetry\Services\TelemetryQueryService;
use App\Filament\Admin\Resources\AutomationThresholdPolicies\AutomationThresholdPolicyResource;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class ThresholdStatusGridSnapshotResolver implements WidgetSnapshotResolver
{
    use InterpretsThresholdStatusSnapshot;

    public function __construct(
        private readonly JsonLogicEvaluator $jsonLogicEvaluator,
        private readonly AlertIncidentManager $alertIncidentManager,
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
        if (! $config instanceof ThresholdStatusGridConfig) {
            throw new InvalidArgumentException('Threshold status grid widgets require ThresholdStatusGridConfig.');
        }

        $organizationId = $widget->dashboard()->value('organization_id');

        if (! is_numeric($organizationId)) {
            return [
                'widget_id' => (int) $widget->id,
                'generated_at' => now()->toIso8601String(),
                'cards' => [],
            ];
        }

        if ($config->scope() === 'device_cards' && $config->deviceCards() !== []) {
            return [
                'widget_id' => (int) $widget->id,
                'generated_at' => now()->toIso8601String(),
                'cards' => $this->resolveConfiguredCards((int) $organizationId, $config),
            ];
        }

        $policies = $this->resolvePolicies((int) $organizationId, $config);
        $openAlerts = $this->alertIncidentManager->openAlertsForThresholdPolicies($this->extractThresholdPolicyIds($policies));
        $latestLogs = $this->fetchLatestLogsForPairs(
            $this->policyPairs($policies),
            $config->lookbackMinutes(),
        );
        $latestAvailableLogs = $this->fetchLatestLogsForPairs($this->policyPairs($policies));

        return [
            'widget_id' => (int) $widget->id,
            'generated_at' => now()->toIso8601String(),
            'cards' => $policies
                ->map(fn (ThresholdPolicy $policy): array => $this->resolveCard(
                    $policy,
                    $latestLogs,
                    $latestAvailableLogs,
                    $openAlerts,
                    $config->displayMode(),
                ))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return Collection<int, ThresholdPolicy>
     */
    private function resolvePolicies(int $organizationId, ThresholdStatusGridConfig $config): Collection
    {
        return ThresholdPolicy::query()
            ->with([
                'device',
                'deviceChannel',
                'notificationProfile',
            ])
            ->where('organization_id', $organizationId)
            ->when(
                $config->scope() === 'selected' && $config->policyIds() !== [],
                fn ($query) => $query->whereIn('id', $config->policyIds()),
                fn ($query) => $query->where('is_active', true),
            )
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  Collection<int, ThresholdPolicy>  $policies
     * @return Collection<int, array{device_id: int, topic_id: int}>
     */
    private function policyPairs(Collection $policies): Collection
    {
        return $policies
            ->map(function (ThresholdPolicy $policy): ?array {
                $topicId = is_numeric($policy->device_channel_id) ? (int) $policy->device_channel_id : 0;

                if ($topicId < 1 || ! is_string($policy->parameter_key) || trim($policy->parameter_key) === '') {
                    return null;
                }

                return [
                    'device_id' => (int) $policy->device_id,
                    'topic_id' => $topicId,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * @param  Collection<int, array{device_id: int, topic_id: int}>  $pairs
     * @return Collection<string, DeviceTelemetryLog>
     */
    private function fetchLatestLogsForPairs(Collection $pairs, ?int $lookbackMinutes = null): Collection
    {
        return $this->telemetryQuery->latestLogsForPairs($pairs, $lookbackMinutes);
    }

    /**
     * @param  Collection<string, DeviceTelemetryLog>  $latestLogs
     * @param  Collection<string, DeviceTelemetryLog>  $latestAvailableLogs
     * @param  Collection<int, Alert>  $openAlerts
     * @return array<string, mixed>
     */
    private function resolveCard(
        ThresholdPolicy $policy,
        Collection $latestLogs,
        Collection $latestAvailableLogs,
        Collection $openAlerts,
        string $displayMode,
    ): array {
        $parameter = $policy->profileParameterDefinition();
        $device = $policy->device;
        $topicId = $parameter instanceof ProfileParameterDefinition
            ? (int) $policy->device_channel_id
            : null;
        $deviceId = (int) $policy->device_id;
        $resolvedLatestLog = $topicId !== null
            ? $latestLogs->get($this->pairKey($deviceId, $topicId))
            : null;
        $latestLog = $resolvedLatestLog instanceof DeviceTelemetryLog ? $resolvedLatestLog : null;
        $resolvedLatestAvailableLog = $topicId !== null
            ? $latestAvailableLogs->get($this->pairKey($deviceId, $topicId))
            : null;
        $latestAvailableLog = $resolvedLatestAvailableLog instanceof DeviceTelemetryLog ? $resolvedLatestAvailableLog : null;
        $payload = is_array($latestLog?->transformed_values) ? $latestLog->transformed_values : [];
        $value = $parameter?->extractValue($payload);
        $numericValue = is_numeric($value) ? (float) $value : null;
        $latestPayload = is_array($latestAvailableLog?->transformed_values) ? $latestAvailableLog->transformed_values : [];
        $latestValue = $parameter?->extractValue($latestPayload);
        $latestNumericValue = is_numeric($latestValue) ? (float) $latestValue : null;
        $connectionState = $device instanceof Device
            ? strtolower($device->effectiveConnectionState())
            : 'unknown';
        $status = $this->resolveStatus(
            policy: $policy,
            connectionState: $connectionState,
            payload: $payload,
            value: $numericValue,
        );
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
        $openAlert = $openAlerts->get((int) $policy->id);
        $openAlert = $openAlert instanceof Alert ? $openAlert : null;

        return [
            'policy_id' => (int) $policy->id,
            'device_name' => $device instanceof Device ? $device->name : $policy->name,
            'connection_state' => strtoupper($connectionState),
            'last_telemetry_at' => $this->toIso8601String($latestLog?->recorded_at),
            'display_timestamp' => $valueSnapshot['display_timestamp'],
            'last_online_at' => $valueSnapshot['last_online_at'],
            'alert_triggered_at' => $status === 'alert' ? $this->toIso8601String($openAlert?->alerted_at) : null,
            'range_label' => $policy->rangeLabel($parameter?->unit),
            'status' => $status,
            'status_label' => strtoupper(str_replace('_', ' ', $status)),
            'current_value' => $valueSnapshot['display_value'],
            'current_value_display' => $valueSnapshot['display_value_display'],
            'current_value_recorded_at' => $valueSnapshot['current_value_recorded_at'],
            'last_value' => $valueSnapshot['last_value'],
            'last_value_display' => $valueSnapshot['last_value_display'],
            'last_value_recorded_at' => $valueSnapshot['last_value_recorded_at'],
            'unit' => $unit,
            'is_active' => (bool) $policy->is_active,
            'edit_url' => AutomationThresholdPolicyResource::getUrl('edit', ['record' => $policy]),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resolveConfiguredCards(int $organizationId, ThresholdStatusGridConfig $config): array
    {
        $resolvedCards = $this->resolveConfiguredCardScopes($organizationId, $config);

        if ($resolvedCards->isEmpty()) {
            return [];
        }

        $latestLogs = $this->fetchLatestLogsForPairs(
            $resolvedCards
                ->map(fn (array $scope): array => [
                    'device_id' => (int) $scope['device']->id,
                    'topic_id' => (int) $scope['topic']->id,
                ]),
            $config->lookbackMinutes(),
        );
        $latestAvailableLogs = $this->fetchLatestLogsForPairs(
            $resolvedCards
                ->map(fn (array $scope): array => [
                    'device_id' => (int) $scope['device']->id,
                    'topic_id' => (int) $scope['topic']->id,
                ]),
        );

        $matchingPolicies = ThresholdPolicy::query()
            ->where('organization_id', $organizationId)
            ->whereIn('device_id', $resolvedCards->pluck('device.id')->unique()->all())
            ->get()
            ->filter(fn (ThresholdPolicy $policy): bool => is_string($policy->parameter_key) && trim($policy->parameter_key) !== '')
            ->keyBy(fn (ThresholdPolicy $policy): string => $this->deviceParameterKey(
                (int) $policy->device_id,
                (string) $policy->parameter_key,
            ));
        $openAlerts = $this->alertIncidentManager->openAlertsForThresholdPolicies($this->extractThresholdPolicyIds($matchingPolicies));

        return array_values(
            $resolvedCards
                ->map(fn (array $scope): array => $this->resolveConfiguredCard(
                    $scope,
                    $latestLogs,
                    $latestAvailableLogs,
                    $matchingPolicies,
                    $openAlerts,
                    $config->displayMode(),
                ))
                ->all(),
        );
    }

    /**
     * @return Collection<int, array{
     *     card: array{
     *         device_id: int,
     *         label: string|null,
     *         parameter_key: string,
     *         minimum_value: float|null,
     *         maximum_value: float|null
     *     },
     *     device: Device,
     *     topic: DeviceChannel,
     *     parameter: ProfileParameterDefinition
     * }>
     */
    private function resolveConfiguredCardScopes(int $organizationId, ThresholdStatusGridConfig $config): Collection
    {
        $deviceIds = collect($config->deviceCards())
            ->pluck('device_id')
            ->unique()
            ->values()
            ->all();

        $devices = Device::query()
            ->with([
                'profileVersion.channels' => fn ($query) => $query
                    ->with([
                        'parameters' => fn ($query) => $query
                            ->where('is_active', true)
                            ->orderBy('sequence')
                            ->orderBy('id'),
                    ]),
            ])
            ->where('organization_id', $organizationId)
            ->whereIn('id', $deviceIds)
            ->get()
            ->keyBy('id');

        return collect($config->deviceCards())
            ->map(function (array $card) use ($devices): ?array {
                $device = $devices->get($card['device_id']);

                if (! $device instanceof Device) {
                    return null;
                }

                $parameter = $this->resolveConfiguredParameter($device, $card['parameter_key']);

                if (! $parameter instanceof ProfileParameterDefinition || ! $parameter->channel instanceof DeviceChannel) {
                    return null;
                }

                return [
                    'card' => $card,
                    'device' => $device,
                    'topic' => $parameter->channel,
                    'parameter' => $parameter,
                ];
            })
            ->filter()
            ->values();
    }

    private function resolveConfiguredParameter(Device $device, string $parameterKey): ?ProfileParameterDefinition
    {
        return $device->profileVersion?->channels
            ?->flatMap(fn (DeviceChannel $channel) => $channel->parameters)
            ->first(fn (ProfileParameterDefinition $parameter): bool => $parameter->key === $parameterKey);
    }

    /**
     * @param  array{
     *     card: array{
     *         device_id: int,
     *         label: string|null,
     *         parameter_key: string,
     *         minimum_value: float|null,
     *         maximum_value: float|null
     *     },
     *     device: Device,
     *     topic: DeviceChannel,
     *     parameter: ProfileParameterDefinition
     * }  $scope
     * @param  Collection<string, DeviceTelemetryLog>  $latestLogs
     * @param  Collection<string, DeviceTelemetryLog>  $latestAvailableLogs
     * @param  Collection<string, ThresholdPolicy>  $matchingPolicies
     * @param  Collection<int, Alert>  $openAlerts
     * @return array<string, mixed>
     */
    private function resolveConfiguredCard(
        array $scope,
        Collection $latestLogs,
        Collection $latestAvailableLogs,
        Collection $matchingPolicies,
        Collection $openAlerts,
        string $displayMode,
    ): array {
        $device = $scope['device'];
        $topic = $scope['topic'];
        $parameter = $scope['parameter'];
        $card = $scope['card'];
        $resolvedLatestLog = $latestLogs->get($this->pairKey((int) $device->id, (int) $topic->id));
        $latestLog = $resolvedLatestLog instanceof DeviceTelemetryLog ? $resolvedLatestLog : null;
        $resolvedLatestAvailableLog = $latestAvailableLogs->get($this->pairKey((int) $device->id, (int) $topic->id));
        $latestAvailableLog = $resolvedLatestAvailableLog instanceof DeviceTelemetryLog ? $resolvedLatestAvailableLog : null;
        $payload = is_array($latestLog?->transformed_values) ? $latestLog->transformed_values : [];
        $value = $parameter->extractValue($payload);
        $numericValue = is_numeric($value) ? (float) $value : null;
        $latestPayload = is_array($latestAvailableLog?->transformed_values) ? $latestAvailableLog->transformed_values : [];
        $latestValue = $parameter->extractValue($latestPayload);
        $latestNumericValue = is_numeric($latestValue) ? (float) $latestValue : null;
        $connectionState = strtolower($device->effectiveConnectionState());
        $policyKey = $this->deviceParameterKey((int) $device->id, $parameter->key);
        $policy = $matchingPolicies->has($policyKey)
            ? $matchingPolicies->get($policyKey)
            : null;
        $hasManualBounds = $card['minimum_value'] !== null || $card['maximum_value'] !== null;
        $minimumValue = $card['minimum_value']
            ?? (is_numeric($policy?->minimum_value) ? (float) $policy->minimum_value : null);
        $maximumValue = $card['maximum_value']
            ?? (is_numeric($policy?->maximum_value) ? (float) $policy->maximum_value : null);
        $usePolicyCondition = $policy !== null && ! $hasManualBounds;
        $status = $usePolicyCondition
            ? $this->resolveStatus(
                policy: $policy,
                connectionState: $connectionState,
                payload: $payload,
                value: $numericValue,
            )
            : $this->resolveBoundedStatus(
                minimumValue: $minimumValue,
                maximumValue: $maximumValue,
                connectionState: $connectionState,
                value: $numericValue,
            );
        $unit = $this->resolveUnitSymbol($parameter->unit);
        $valueSnapshot = $this->resolveThresholdValueSnapshot(
            status: $status,
            device: $device,
            latestRecentLog: $latestLog,
            latestAvailableLog: $latestAvailableLog,
            currentValue: $numericValue,
            lastValue: $latestNumericValue,
            unit: $unit,
        );
        $openAlert = null;

        if ($usePolicyCondition) {
            $resolvedOpenAlert = $openAlerts->get((int) $policy->id);
            $openAlert = $resolvedOpenAlert instanceof Alert ? $resolvedOpenAlert : null;
        }

        return [
            'policy_id' => $policy?->id === null ? null : (int) $policy->id,
            'device_name' => $card['label'] ?? $device->name,
            'connection_state' => strtoupper($connectionState),
            'last_telemetry_at' => $this->toIso8601String($latestLog?->recorded_at),
            'display_timestamp' => $valueSnapshot['display_timestamp'],
            'last_online_at' => $valueSnapshot['last_online_at'],
            'alert_triggered_at' => $status === 'alert' ? $this->toIso8601String($openAlert?->alerted_at) : null,
            'range_label' => $this->resolveRangeLabel(
                minimumValue: $minimumValue,
                maximumValue: $maximumValue,
                unit: $parameter->unit,
                displayMode: $displayMode,
            ),
            'status' => $status,
            'status_label' => strtoupper(str_replace('_', ' ', $status)),
            'current_value' => $valueSnapshot['display_value'],
            'current_value_display' => $valueSnapshot['display_value_display'],
            'current_value_recorded_at' => $valueSnapshot['current_value_recorded_at'],
            'last_value' => $valueSnapshot['last_value'],
            'last_value_display' => $valueSnapshot['last_value_display'],
            'last_value_recorded_at' => $valueSnapshot['last_value_recorded_at'],
            'unit' => $unit,
            'is_active' => $policy !== null ? (bool) $policy->is_active : true,
            'edit_url' => $policy !== null
                ? AutomationThresholdPolicyResource::getUrl('edit', ['record' => $policy])
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveStatus(
        ThresholdPolicy $policy,
        string $connectionState,
        array $payload,
        ?float $value,
    ): string {
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

    private function resolveBoundedStatus(
        ?float $minimumValue,
        ?float $maximumValue,
        string $connectionState,
        ?float $value,
    ): string {
        if ($connectionState === 'offline') {
            return 'offline';
        }

        if ($value === null) {
            return 'no_data';
        }

        if ($minimumValue !== null && $value < $minimumValue) {
            return 'alert';
        }

        if ($maximumValue !== null && $value > $maximumValue) {
            return 'alert';
        }

        return 'normal';
    }

    private function resolveRangeLabel(
        ?float $minimumValue,
        ?float $maximumValue,
        ?string $unit,
        string $displayMode,
    ): string {
        if ($displayMode === 'sri_lankan_temperature') {
            return $this->resolveSriLankanRangeLabel($minimumValue, $maximumValue, $unit);
        }

        if ($minimumValue !== null && $maximumValue !== null) {
            return sprintf(
                '%s to %s',
                $this->formatValue($minimumValue, $this->resolveUnitSymbol($unit)),
                $this->formatValue($maximumValue, $this->resolveUnitSymbol($unit)),
            );
        }

        if ($minimumValue !== null) {
            return 'At least '.$this->formatValue($minimumValue, $this->resolveUnitSymbol($unit));
        }

        if ($maximumValue !== null) {
            return 'At most '.$this->formatValue($maximumValue, $this->resolveUnitSymbol($unit));
        }

        return 'No thresholds configured';
    }

    private function resolveSriLankanRangeLabel(?float $minimumValue, ?float $maximumValue, ?string $unit): string
    {
        if ($minimumValue !== null && $maximumValue !== null) {
            return sprintf(
                '%s > Temp > %s',
                $this->formatValue($minimumValue, $this->resolveUnitSymbol($unit)),
                $this->formatValue($maximumValue, $this->resolveUnitSymbol($unit)),
            );
        }

        if ($minimumValue !== null) {
            return 'Temp > '.$this->formatValue($minimumValue, $this->resolveUnitSymbol($unit));
        }

        if ($maximumValue !== null) {
            return 'Temp < '.$this->formatValue($maximumValue, $this->resolveUnitSymbol($unit));
        }

        return 'No thresholds configured';
    }

    private function pairKey(int $deviceId, int $topicId): string
    {
        return "{$deviceId}:{$topicId}";
    }

    private function deviceParameterKey(int $deviceId, string $parameterKey): string
    {
        return "{$deviceId}:{$parameterKey}";
    }

    /**
     * @param  iterable<ThresholdPolicy>  $policies
     * @return list<int>
     */
    private function extractThresholdPolicyIds(iterable $policies): array
    {
        $resolvedPolicyIds = [];

        foreach ($policies as $policy) {
            $resolvedId = $this->resolvePositiveInt($policy->id);

            if ($resolvedId !== null) {
                $resolvedPolicyIds[$resolvedId] = $resolvedId;
            }
        }

        return array_values($resolvedPolicyIds);
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

    private function resolvePositiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value) || ! ctype_digit($value)) {
            return null;
        }

        $resolvedValue = (int) $value;

        return $resolvedValue > 0 ? $resolvedValue : null;
    }
}
