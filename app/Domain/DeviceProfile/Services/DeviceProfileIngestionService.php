<?php

declare(strict_types=1);

namespace App\Domain\DeviceProfile\Services;

use App\Domain\DataIngestion\Models\IngestionMessage;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\DTO\ChannelDefinition;
use App\Domain\DeviceProfile\DTO\DeviceProfileContract;
use App\Domain\DeviceProfile\DTO\IngestionOutcome;
use App\Domain\Telemetry\Enums\ValidationStatus;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use Illuminate\Support\Carbon;

/**
 * Orchestrates the profile-driven telemetry pipeline:
 * validate → mutate → derive → persist. Driven entirely by DTOs and
 * producing the same transformed_values shape as the legacy pipeline.
 */
class DeviceProfileIngestionService
{
    public function __construct(
        private readonly ProfileTelemetryValidator $validator,
        private readonly ProfileTelemetryMutator $mutator,
        private readonly ProfileTelemetryDeriver $deriver,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function ingest(
        array $payload,
        Device $device,
        ChannelDefinition $channel,
        DeviceProfileContract $contract,
        ?Carbon $receivedAt = null,
        ?IngestionMessage $ingestionMessage = null,
    ): IngestionOutcome {
        $resolvedReceivedAt = $receivedAt ?? now();
        $resolvedRecordedAt = $resolvedReceivedAt;

        $validation = $this->validator->validate($payload, $channel);

        if ($validation->isInvalid()) {
            $telemetryLog = $this->persist(
                device: $device,
                contract: $contract,
                channel: $channel,
                rawPayload: $payload,
                finalValues: $validation->extractedValues,
                validationStatus: $validation->status,
                processingState: 'invalid',
                mutatedValues: null,
                validationErrors: $validation->validationErrors,
                recordedAt: $resolvedRecordedAt,
                receivedAt: $resolvedReceivedAt,
                ingestionMessage: $ingestionMessage,
            );

            return new IngestionOutcome(
                processingState: 'invalid',
                validationStatus: $validation->status,
                extractedValues: $validation->extractedValues,
                mutatedValues: null,
                finalValues: $validation->extractedValues,
                validationErrors: $validation->validationErrors,
                telemetryLog: $telemetryLog,
            );
        }

        if (! $device->is_active) {
            $telemetryLog = $this->persist(
                device: $device,
                contract: $contract,
                channel: $channel,
                rawPayload: $payload,
                finalValues: $validation->extractedValues,
                validationStatus: $validation->status,
                processingState: 'inactive_skipped',
                mutatedValues: null,
                validationErrors: $validation->validationErrors,
                recordedAt: $resolvedRecordedAt,
                receivedAt: $resolvedReceivedAt,
                ingestionMessage: $ingestionMessage,
            );

            return new IngestionOutcome(
                processingState: 'inactive_skipped',
                validationStatus: $validation->status,
                extractedValues: $validation->extractedValues,
                mutatedValues: null,
                finalValues: $validation->extractedValues,
                validationErrors: $validation->validationErrors,
                telemetryLog: $telemetryLog,
            );
        }

        $mutation = $this->mutator->mutate($validation->extractedValues, $channel);
        $derivation = $this->deriver->derive($mutation->mutatedValues, $contract->derivedParameters());

        $telemetryLog = $this->persist(
            device: $device,
            contract: $contract,
            channel: $channel,
            rawPayload: $payload,
            finalValues: $derivation->finalValues,
            validationStatus: $validation->status,
            processingState: 'processed',
            mutatedValues: $mutation->mutatedValues,
            validationErrors: $validation->validationErrors,
            recordedAt: $resolvedRecordedAt,
            receivedAt: $resolvedReceivedAt,
            ingestionMessage: $ingestionMessage,
        );

        return new IngestionOutcome(
            processingState: 'processed',
            validationStatus: $validation->status,
            extractedValues: $validation->extractedValues,
            mutatedValues: $mutation->mutatedValues,
            finalValues: $derivation->finalValues,
            validationErrors: $validation->validationErrors,
            telemetryLog: $telemetryLog,
        );
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     * @param  array<string, mixed>  $finalValues
     * @param  array<string, mixed>|null  $mutatedValues
     * @param  array<string, mixed>  $validationErrors
     */
    private function persist(
        Device $device,
        DeviceProfileContract $contract,
        ChannelDefinition $channel,
        array $rawPayload,
        array $finalValues,
        ValidationStatus $validationStatus,
        string $processingState,
        ?array $mutatedValues,
        array $validationErrors,
        Carbon $recordedAt,
        Carbon $receivedAt,
        ?IngestionMessage $ingestionMessage = null,
    ): DeviceTelemetryLog {
        $telemetryLog = DeviceTelemetryLog::create([
            'device_id' => $device->id,
            'device_profile_version_id' => $contract->versionId,
            'device_channel_id' => $channel->id,
            'ingestion_message_id' => $ingestionMessage?->id,
            'validation_status' => $validationStatus,
            'processing_state' => $processingState,
            'raw_payload' => $rawPayload,
            'transformed_values' => $finalValues,
            'mutated_values' => $mutatedValues,
            'validation_errors' => $validationErrors !== [] ? $validationErrors : null,
            'recorded_at' => $recordedAt,
            'received_at' => $receivedAt,
        ]);

        $telemetryLog->setRelation('device', $device);

        return $telemetryLog;
    }
}
