<?php

declare(strict_types=1);

namespace App\Domain\Alerts\Services;

use App\Domain\Alerts\Models\Alert;
use App\Domain\Alerts\Models\ThresholdPolicy;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AlertIncidentManager
{
    /**
     * @return array{alert: Alert, was_created: bool}
     */
    public function openThresholdAlert(ThresholdPolicy $policy, DeviceTelemetryLog $telemetryLog): array
    {
        return DB::transaction(function () use ($policy, $telemetryLog): array {
            $policyId = $this->requirePositiveInt($policy->id, 'threshold policy id');

            ThresholdPolicy::query()
                ->whereKey($policyId)
                ->lockForUpdate()
                ->first();

            $existingAlert = Alert::query()
                ->open()
                ->where('threshold_policy_id', $policyId)
                ->lockForUpdate()
                ->first();

            if ($existingAlert instanceof Alert) {
                return [
                    'alert' => $existingAlert,
                    'was_created' => false,
                ];
            }

            $alert = Alert::query()->create([
                'organization_id' => $this->requirePositiveInt($policy->organization_id, 'organization id'),
                'threshold_policy_id' => $policyId,
                'device_id' => $this->requirePositiveInt($policy->device_id, 'device id'),
                'device_channel_id' => $this->requirePositiveInt($policy->device_channel_id, 'device channel id'),
                'parameter_key' => $this->requireNonEmptyString($policy->parameter_key, 'parameter key'),
                'alerted_at' => $telemetryLog->recorded_at,
                'alerted_telemetry_log_id' => $this->requireStringKey($telemetryLog->getKey(), 'telemetry log id'),
            ]);

            return [
                'alert' => $alert,
                'was_created' => true,
            ];
        });
    }

    public function normalizeThresholdAlert(ThresholdPolicy $policy, ?DeviceTelemetryLog $telemetryLog = null): ?Alert
    {
        return DB::transaction(function () use ($policy, $telemetryLog): ?Alert {
            $policyId = $this->requirePositiveInt($policy->id, 'threshold policy id');

            ThresholdPolicy::query()
                ->whereKey($policyId)
                ->lockForUpdate()
                ->first();

            $alert = Alert::query()
                ->open()
                ->where('threshold_policy_id', $policyId)
                ->lockForUpdate()
                ->first();

            if (! $alert instanceof Alert) {
                return null;
            }

            $normalizedAt = $telemetryLog instanceof DeviceTelemetryLog
                ? $telemetryLog->recorded_at
                : now();

            $alert->forceFill([
                'normalized_at' => $normalizedAt,
                'normalized_telemetry_log_id' => $telemetryLog instanceof DeviceTelemetryLog
                    ? $this->requireStringKey($telemetryLog->getKey(), 'telemetry log id')
                    : null,
            ])->save();

            return $alert->fresh() ?? $alert;
        });
    }

    public function markAlertNotificationSent(Alert $alert, ?CarbonInterface $sentAt = null): Alert
    {
        $alert->forceFill([
            'alert_notification_sent_at' => $sentAt ?? now(),
        ])->save();

        return $alert->fresh() ?? $alert;
    }

    public function markNormalizedNotificationSent(Alert $alert, ?CarbonInterface $sentAt = null): Alert
    {
        $alert->forceFill([
            'normalized_notification_sent_at' => $sentAt ?? now(),
        ])->save();

        return $alert->fresh() ?? $alert;
    }

    /**
     * @param  iterable<int, int|string>  $policyIds
     * @return Collection<int, Alert>
     */
    public function openAlertsForThresholdPolicies(iterable $policyIds): Collection
    {
        $resolvedPolicyIds = $this->normalizePolicyIds($policyIds);

        if ($resolvedPolicyIds === []) {
            return collect();
        }

        return Alert::query()
            ->open()
            ->whereIn('threshold_policy_id', $resolvedPolicyIds)
            ->orderByDesc('alerted_at')
            ->orderByDesc('id')
            ->get()
            ->keyBy(fn (Alert $alert): int => (int) $alert->threshold_policy_id);
    }

    /**
     * @param  iterable<int, int|string>  $policyIds
     * @return list<int>
     */
    private function normalizePolicyIds(iterable $policyIds): array
    {
        $resolvedPolicyIds = [];

        foreach ($policyIds as $policyId) {
            if (is_int($policyId) && $policyId > 0) {
                $resolvedPolicyIds[$policyId] = $policyId;

                continue;
            }

            if (! is_string($policyId) || ! ctype_digit($policyId)) {
                continue;
            }

            $resolvedValue = (int) $policyId;

            if ($resolvedValue > 0) {
                $resolvedPolicyIds[$resolvedValue] = $resolvedValue;
            }
        }

        return array_values($resolvedPolicyIds);
    }

    private function requirePositiveInt(mixed $value, string $field): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            $resolvedValue = (int) $value;

            if ($resolvedValue > 0) {
                return $resolvedValue;
            }
        }

        throw new RuntimeException("Unable to resolve {$field}.");
    }

    private function requireStringKey(mixed $value, string $field): string
    {
        if (is_int($value) || is_string($value)) {
            $resolvedValue = (string) $value;

            if ($resolvedValue !== '') {
                return $resolvedValue;
            }
        }

        throw new RuntimeException("Unable to resolve {$field}.");
    }

    private function requireNonEmptyString(mixed $value, string $field): string
    {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        throw new RuntimeException("Unable to resolve {$field}.");
    }
}
