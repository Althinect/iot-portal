<?php

declare(strict_types=1);

namespace App\Domain\Telemetry\Services;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Models\ProfileDerivedParameterDefinition;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use App\Domain\Telemetry\Enums\ValidationStatus;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use App\Events\TelemetryReceived;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

class TelemetryLogRecorder
{
    /**
     * Record a telemetry log entry for a device.
     *
     * @param  array<string, mixed>  $payload
     */
    public function record(
        Device $device,
        array $payload,
        ?Carbon $recordedAt = null,
        ?Carbon $receivedAt = null,
        ?string $topicSuffix = null,
    ): DeviceTelemetryLog {
        $device->loadMissing('profileVersion.channels.parameters', 'profileVersion.derivedParameters');

        $profileVersion = $device->profileVersion;

        if (! $profileVersion instanceof DeviceProfileVersion) {
            throw new RuntimeException('Device profile version is required to record telemetry logs.');
        }

        $channel = $this->resolveChannel($profileVersion, $topicSuffix);

        $parameters = $channel instanceof DeviceChannel
            ? $channel->parameters
                ->where('is_active', true)
                ->sortBy('sequence')
                ->values()
            : $profileVersion->channels
                ->filter(fn (DeviceChannel $candidate): bool => $candidate->isPublish())
                ->flatMap(fn (DeviceChannel $candidate): Collection => $candidate->parameters)
                ->where('is_active', true)
                ->sortBy('sequence')
                ->values();

        $derivedParameters = $profileVersion->derivedParameters;

        [$transformedValues, $validationStatus] = $this->evaluatePayload($payload, $parameters, $derivedParameters);

        $resolvedReceivedAt = $receivedAt ?? Carbon::now();
        $resolvedRecordedAt = $recordedAt ?? $resolvedReceivedAt;

        $log = DeviceTelemetryLog::create([
            'device_id' => $device->id,
            'device_profile_version_id' => $profileVersion->id,
            'device_channel_id' => $channel?->id,
            'raw_payload' => $payload,
            'transformed_values' => $transformedValues,
            'validation_status' => $validationStatus,
            'recorded_at' => $resolvedRecordedAt,
            'received_at' => $resolvedReceivedAt,
        ]);

        $log->loadMissing('device');

        event(new TelemetryReceived($log));

        return $log;
    }

    private function resolveChannel(DeviceProfileVersion $profileVersion, ?string $topicSuffix): ?DeviceChannel
    {
        if ($topicSuffix === null) {
            return null;
        }

        return $profileVersion->channels
            ->filter(fn (DeviceChannel $channel): bool => $channel->isPublish())
            ->first(fn (DeviceChannel $channel): bool => $channel->address === $topicSuffix || $channel->key === $topicSuffix);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  Collection<int, ProfileParameterDefinition>  $parameters
     * @param  Collection<int, ProfileDerivedParameterDefinition>  $derivedParameters
     * @return array{0: array<string, mixed>, 1: ValidationStatus}
     */
    private function evaluatePayload(array $payload, Collection $parameters, Collection $derivedParameters): array
    {
        $transformedValues = [];
        $hasInvalid = false;
        $hasCriticalInvalid = false;

        foreach ($parameters as $parameter) {
            $rawValue = $parameter->extractValue($payload);
            $mutatedValue = $parameter->mutateValue($rawValue);
            $validation = $parameter->validateValue($mutatedValue);

            $transformedValues[$parameter->key] = $mutatedValue;

            if ($validation['is_valid'] === false) {
                $hasInvalid = true;

                if ($validation['is_critical'] === true) {
                    $hasCriticalInvalid = true;
                }
            }
        }

        $derivedValues = $this->evaluateDerivedParameters($derivedParameters, $transformedValues);

        $transformedValues = array_merge($transformedValues, $derivedValues);

        $status = match (true) {
            $hasCriticalInvalid => ValidationStatus::Invalid,
            $hasInvalid => ValidationStatus::Warning,
            default => ValidationStatus::Valid,
        };

        return [$transformedValues, $status];
    }

    /**
     * @param  Collection<int, ProfileDerivedParameterDefinition>  $derivedParameters
     * @param  array<string, mixed>  $inputs
     * @return array<string, mixed>
     */
    private function evaluateDerivedParameters(Collection $derivedParameters, array $inputs): array
    {
        $pending = $derivedParameters->keyBy('key')->all();
        $resolved = $inputs;
        $derivedValues = [];
        $maxIterations = count($pending);
        $iterations = 0;

        while ($pending !== [] && $iterations < $maxIterations) {
            $progress = false;

            foreach ($pending as $key => $definition) {
                $dependencies = $definition->resolvedDependencies();

                if (array_diff($dependencies, array_keys($resolved)) !== []) {
                    continue;
                }

                $value = $definition->evaluate($resolved);
                $derivedValues[$key] = $value;
                $resolved[$key] = $value;
                unset($pending[$key]);
                $progress = true;
            }

            if (! $progress) {
                break;
            }

            $iterations++;
        }

        return $derivedValues;
    }
}
