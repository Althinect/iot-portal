<?php

declare(strict_types=1);

namespace App\Domain\DataIngestion\Services;

use App\Domain\DataIngestion\DTO\IncomingTelemetryEnvelope;
use App\Domain\DataIngestion\Enums\IngestionStage;
use App\Domain\DataIngestion\Enums\IngestionStatus;
use App\Domain\DataIngestion\Models\IngestionMessage;
use App\Domain\DataIngestion\Models\IngestionStageLog;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Domain\Shared\Services\RuntimeSettingManager;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use App\Events\TelemetryReceived;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TelemetryIngestionService
{
    public function __construct(
        private readonly DeviceTelemetryTopicResolver $topicResolver,
        private readonly TelemetrySchemaMetadataCache $schemaMetadataCache,
        private readonly TelemetryValidationService $validationService,
        private readonly TelemetryMutationService $mutationService,
        private readonly TelemetryDerivationService $derivationService,
        private readonly TelemetryPersistenceService $persistenceService,
        private readonly RuntimeSettingManager $runtimeSettingManager,
    ) {}

    public function ingest(IncomingTelemetryEnvelope $envelope): ?IngestionMessage
    {
        if (! $this->runtimeSettingManager->booleanValue('ingestion.pipeline.enabled')) {
            return null;
        }

        if ($this->runtimeSettingManager->stringValue('ingestion.pipeline.driver') !== 'laravel') {
            return null;
        }

        $queueAttempt = $this->createQueuedIngestionMessage($envelope);
        $ingestionMessage = $queueAttempt['message'];

        if (! $queueAttempt['should_continue']) {
            return $ingestionMessage;
        }

        $resolved = $this->topicResolver->resolve($envelope->mqttTopic);

        if ($resolved === null) {
            $ingestionMessage->update([
                'status' => IngestionStatus::FailedTerminal,
                'error_summary' => [
                    'reason' => 'topic_not_registered',
                    'mqtt_topic' => $envelope->mqttTopic,
                ],
                'processed_at' => now(),
            ]);

            $this->logStage(
                ingestionMessage: $ingestionMessage,
                stage: IngestionStage::Ingress,
                status: IngestionStatus::FailedTerminal,
                inputSnapshot: [
                    'mqtt_topic' => $envelope->mqttTopic,
                    'payload' => $envelope->payload,
                ],
                errors: [
                    'reason' => 'topic_not_registered',
                ],
            );

            return $ingestionMessage;
        }

        /** @var Device $device */
        $device = $resolved['device'];

        /** @var SchemaVersionTopic $topic */
        $topic = $resolved['topic'];

        if (! $this->runtimeSettingManager->booleanValue('ingestion.pipeline.enabled', $device->organization_id)) {
            $this->finalizeMessage($ingestionMessage, [
                'organization_id' => $device->organization_id,
                'device_id' => $device->id,
                'device_schema_version_id' => $device->device_schema_version_id,
                'schema_version_topic_id' => $topic->id,
                'status' => IngestionStatus::FailedTerminal,
                'error_summary' => [
                    'reason' => 'organization_pipeline_disabled',
                ],
            ]);

            return $ingestionMessage;
        }

        if ($this->runtimeSettingManager->stringValue('ingestion.pipeline.driver', $device->organization_id) !== 'laravel') {
            $this->finalizeMessage($ingestionMessage, [
                'organization_id' => $device->organization_id,
                'device_id' => $device->id,
                'device_schema_version_id' => $device->device_schema_version_id,
                'schema_version_topic_id' => $topic->id,
                'status' => IngestionStatus::FailedTerminal,
                'error_summary' => [
                    'reason' => 'organization_driver_unsupported',
                ],
            ]);

            return $ingestionMessage;
        }

        $schemaVersion = $device->schemaVersion;
        $messageContext = [
            'organization_id' => $device->organization_id,
            'device_id' => $device->id,
            'schema_version_topic_id' => $topic->id,
        ];

        if ($schemaVersion === null) {
            $this->finalizeMessage($ingestionMessage, [
                ...$messageContext,
                'status' => IngestionStatus::FailedTerminal,
                'error_summary' => [
                    'reason' => 'schema_version_missing',
                ],
            ]);

            return $ingestionMessage;
        }

        $messageContext['device_schema_version_id'] = $schemaVersion->id;

        $this->logStage(
            ingestionMessage: $ingestionMessage,
            stage: IngestionStage::Ingress,
            status: IngestionStatus::Completed,
            inputSnapshot: [
                'source_subject' => $envelope->sourceSubject,
                'mqtt_topic' => $envelope->mqttTopic,
            ],
            outputSnapshot: [
                'device_id' => $device->id,
                'schema_version_topic_id' => $topic->id,
            ],
        );

        $parameters = $this->resolveActiveParameters($topic);
        $derivedParameters = $this->schemaMetadataCache->derivedParametersFor($schemaVersion);

        $validationStartedAt = microtime(true);

        $validationResult = $this->validationService->validate($envelope->payload, $parameters);

        $validationStatus = $validationResult['passes'] === true
            ? IngestionStatus::Completed
            : IngestionStatus::FailedValidation;

        $this->logStage(
            ingestionMessage: $ingestionMessage,
            stage: IngestionStage::Validate,
            status: $validationStatus,
            startedAt: $validationStartedAt,
            inputSnapshot: [
                'payload' => $envelope->payload,
            ],
            outputSnapshot: [
                'extracted_values' => $validationResult['extracted_values'],
                'status' => $validationResult['status']->value,
            ],
            errors: $validationResult['validation_errors'],
        );

        if ($validationResult['passes'] !== true) {
            $telemetryLog = $this->persistenceService->persist(
                device: $device,
                schemaVersion: $schemaVersion,
                topic: $topic,
                rawPayload: $envelope->payload,
                finalValues: $validationResult['extracted_values'],
                validationStatus: $validationResult['status'],
                ingestionMessage: $ingestionMessage,
                processingState: 'invalid',
                validationErrors: $validationResult['validation_errors'],
                receivedAt: $envelope->resolveReceivedAt(),
            );

            $this->finalizeMessage($ingestionMessage, [
                ...$messageContext,
                'status' => IngestionStatus::FailedValidation,
                'error_summary' => [
                    'validation_errors' => $validationResult['validation_errors'],
                ],
            ]);

            $this->dispatchTelemetrySideEffects($ingestionMessage, $telemetryLog);

            return $ingestionMessage;
        }

        if (! $device->is_active) {
            $telemetryLog = $this->persistenceService->persist(
                device: $device,
                schemaVersion: $schemaVersion,
                topic: $topic,
                rawPayload: $envelope->payload,
                finalValues: $validationResult['extracted_values'],
                validationStatus: $validationResult['status'],
                ingestionMessage: $ingestionMessage,
                processingState: 'inactive_skipped',
                receivedAt: $envelope->resolveReceivedAt(),
            );

            $this->finalizeMessage($ingestionMessage, [
                ...$messageContext,
                'status' => IngestionStatus::InactiveSkipped,
            ]);

            $this->dispatchTelemetrySideEffects($ingestionMessage, $telemetryLog);

            return $ingestionMessage;
        }

        $mutationStartedAt = microtime(true);
        $mutationResult = $this->mutationService->mutate($validationResult['extracted_values'], $parameters);

        $this->logStage(
            ingestionMessage: $ingestionMessage,
            stage: IngestionStage::Mutate,
            status: IngestionStatus::Completed,
            startedAt: $mutationStartedAt,
            inputSnapshot: [
                'extracted_values' => $validationResult['extracted_values'],
            ],
            outputSnapshot: [
                'mutated_values' => $mutationResult['mutated_values'],
            ],
            changeSet: $mutationResult['change_set'],
        );

        $derivationStartedAt = microtime(true);
        $derivationResult = $this->derivationService->derive($mutationResult['mutated_values'], $derivedParameters);

        $this->logStage(
            ingestionMessage: $ingestionMessage,
            stage: IngestionStage::Derive,
            status: IngestionStatus::Completed,
            startedAt: $derivationStartedAt,
            inputSnapshot: [
                'mutated_values' => $mutationResult['mutated_values'],
            ],
            outputSnapshot: [
                'derived_values' => $derivationResult['derived_values'],
                'final_values' => $derivationResult['final_values'],
            ],
        );

        $persistStartedAt = microtime(true);

        $telemetryLog = $this->persistenceService->persist(
            device: $device,
            schemaVersion: $schemaVersion,
            topic: $topic,
            rawPayload: $envelope->payload,
            finalValues: $derivationResult['final_values'],
            validationStatus: $validationResult['status'],
            ingestionMessage: $ingestionMessage,
            processingState: 'processed',
            mutatedValues: $mutationResult['mutated_values'],
            receivedAt: $envelope->resolveReceivedAt(),
        );

        $this->logStage(
            ingestionMessage: $ingestionMessage,
            stage: IngestionStage::Persist,
            status: IngestionStatus::Completed,
            startedAt: $persistStartedAt,
            outputSnapshot: [
                'device_telemetry_log_id' => $telemetryLog->id,
            ],
        );

        $this->finalizeMessage($ingestionMessage, [
            ...$messageContext,
            'status' => IngestionStatus::Completed,
        ]);

        $this->dispatchTelemetrySideEffects($ingestionMessage, $telemetryLog);

        return $ingestionMessage;
    }

    private function dispatchTelemetrySideEffects(IngestionMessage $ingestionMessage, DeviceTelemetryLog $telemetryLog): void
    {
        $dispatchStartedAt = microtime(true);
        $dispatchOutput = [
            'telemetry_log_id' => $telemetryLog->id,
            'queue_connection' => $this->resolveSideEffectsQueueConnection(),
            'queue' => $this->resolveSideEffectsQueue(),
            'side_effects_dispatched' => false,
        ];

        try {
            event(new TelemetryReceived($telemetryLog));
            $dispatchOutput['side_effects_dispatched'] = true;

            $this->logStage(
                ingestionMessage: $ingestionMessage,
                stage: IngestionStage::Publish,
                status: IngestionStatus::Completed,
                startedAt: $dispatchStartedAt,
                outputSnapshot: $dispatchOutput,
            );
        } catch (\Throwable $exception) {
            report($exception);

            $this->logStage(
                ingestionMessage: $ingestionMessage,
                stage: IngestionStage::Publish,
                status: IngestionStatus::FailedTerminal,
                startedAt: $dispatchStartedAt,
                outputSnapshot: $dispatchOutput,
                errors: [
                    'side_effect_dispatch' => $exception->getMessage(),
                ],
            );
        }
    }

    /**
     * @return Collection<int, \App\Domain\DeviceSchema\Models\ParameterDefinition>
     */
    private function resolveActiveParameters(SchemaVersionTopic $topic): Collection
    {
        return $this->schemaMetadataCache->activeParametersFor($topic);
    }

    /**
     * @param  array<string, mixed>  $inputSnapshot
     * @param  array<string, mixed>  $outputSnapshot
     * @param  array<string, mixed>  $changeSet
     * @param  array<string, mixed>  $errors
     */
    private function logStage(
        IngestionMessage $ingestionMessage,
        IngestionStage $stage,
        IngestionStatus $status,
        ?float $startedAt = null,
        array $inputSnapshot = [],
        array $outputSnapshot = [],
        array $changeSet = [],
        array $errors = [],
    ): void {
        if (! $this->shouldPersistStageLog($ingestionMessage, $status)) {
            return;
        }

        $captureSnapshots = (bool) config('ingestion.capture_stage_snapshots', true);
        $captureSuccessfulSnapshots = (bool) config('ingestion.capture_success_stage_snapshots', false);
        $shouldCaptureSnapshots = $captureSnapshots
            && ($status !== IngestionStatus::Completed || $captureSuccessfulSnapshots);

        IngestionStageLog::create([
            'ingestion_message_id' => $ingestionMessage->id,
            'stage' => $stage,
            'status' => $status,
            'duration_ms' => is_numeric($startedAt)
                ? (int) round((microtime(true) - (float) $startedAt) * 1000)
                : null,
            'input_snapshot' => $shouldCaptureSnapshots ? $inputSnapshot : null,
            'output_snapshot' => $shouldCaptureSnapshots ? $outputSnapshot : null,
            'change_set' => $shouldCaptureSnapshots && $changeSet !== [] ? $changeSet : null,
            'errors' => $errors !== [] ? $errors : null,
        ]);
    }

    private function shouldPersistStageLog(IngestionMessage $ingestionMessage, IngestionStatus $status): bool
    {
        if ($status !== IngestionStatus::Completed) {
            return true;
        }

        return match ($this->resolveStageLogMode()) {
            'all' => true,
            'sampled' => $this->shouldSampleSuccessfulStageLogs($ingestionMessage),
            default => false,
        };
    }

    private function resolveStageLogMode(): string
    {
        $configuredMode = config('ingestion.stage_log_mode', 'failures');

        if (! is_string($configuredMode)) {
            return 'failures';
        }

        return in_array($configuredMode, ['failures', 'sampled', 'all'], true)
            ? $configuredMode
            : 'failures';
    }

    private function shouldSampleSuccessfulStageLogs(IngestionMessage $ingestionMessage): bool
    {
        $configuredSampleRate = config('ingestion.stage_log_sample_rate', 0.0);

        if (! is_numeric($configuredSampleRate)) {
            return false;
        }

        $sampleRate = max(0.0, min(1.0, (float) $configuredSampleRate));

        if ($sampleRate === 0.0) {
            return false;
        }

        if ($sampleRate === 1.0) {
            return true;
        }

        $samplingKey = $ingestionMessage->source_deduplication_key;

        if ($samplingKey === '') {
            $resolvedKey = $ingestionMessage->getKey();
            $samplingKey = is_int($resolvedKey) || is_string($resolvedKey)
                ? (string) $resolvedKey
                : spl_object_hash($ingestionMessage);
        }

        $normalizedHash = (float) sprintf('%u', crc32($samplingKey));

        return ($normalizedHash / 4_294_967_295) < $sampleRate;
    }

    private function resolveSideEffectsQueueConnection(): string
    {
        $configuredConnection = config('ingestion.side_effects_queue_connection', config('queue.default', 'redis'));

        return is_string($configuredConnection) && $configuredConnection !== ''
            ? $configuredConnection
            : 'redis';
    }

    private function resolveSideEffectsQueue(): string
    {
        $configuredQueue = config('ingestion.side_effects_queue', 'telemetry-side-effects');

        return is_string($configuredQueue) && $configuredQueue !== ''
            ? $configuredQueue
            : 'telemetry-side-effects';
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function finalizeMessage(IngestionMessage $ingestionMessage, array $attributes): void
    {
        $ingestionMessage->update([
            ...$attributes,
            'processed_at' => now(),
        ]);
    }

    /**
     * @return array{message: IngestionMessage, should_continue: bool}
     */
    private function createQueuedIngestionMessage(IncomingTelemetryEnvelope $envelope): array
    {
        $timestamp = now();
        $attributes = [
            'id' => (string) Str::uuid(),
            'source_deduplication_key' => $envelope->deduplicationKey(),
            'source_subject' => $envelope->sourceSubject,
            'source_protocol' => 'mqtt',
            'source_message_id' => $envelope->messageId,
            'raw_payload' => $envelope->payload,
            'status' => IngestionStatus::Queued,
            'received_at' => $envelope->resolveReceivedAt(),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];

        if (IngestionMessage::query()->insertOrIgnore([
            'id' => $attributes['id'],
            'source_deduplication_key' => $attributes['source_deduplication_key'],
            'source_subject' => $attributes['source_subject'],
            'source_protocol' => $attributes['source_protocol'],
            'source_message_id' => $attributes['source_message_id'],
            'raw_payload' => json_encode($attributes['raw_payload'], JSON_THROW_ON_ERROR),
            'status' => IngestionStatus::Queued->value,
            'received_at' => $attributes['received_at']->toDateTimeString(),
            'created_at' => $timestamp->toDateTimeString(),
            'updated_at' => $timestamp->toDateTimeString(),
        ]) === 1) {
            $ingestionMessage = new IngestionMessage;
            $ingestionMessage->forceFill($attributes);
            $ingestionMessage->exists = true;
            $ingestionMessage->wasRecentlyCreated = true;

            return [
                'message' => $ingestionMessage,
                'should_continue' => true,
            ];
        }

        $ingestionMessage = IngestionMessage::query()
            ->select(['id', 'status', 'source_deduplication_key'])
            ->where('source_deduplication_key', $envelope->deduplicationKey())
            ->first();

        if (! $ingestionMessage instanceof IngestionMessage) {
            throw new \RuntimeException('Duplicate ingestion message could not be resolved.');
        }

        $currentStatus = $ingestionMessage->getAttribute('status');
        $isDuplicate = $currentStatus instanceof IngestionStatus
            ? $currentStatus === IngestionStatus::Duplicate
            : $currentStatus === IngestionStatus::Duplicate->value;

        if (! $isDuplicate) {
            IngestionMessage::query()
                ->whereKey($ingestionMessage->getKey())
                ->where('status', '!=', IngestionStatus::Duplicate->value)
                ->update([
                    'status' => IngestionStatus::Duplicate,
                    'updated_at' => now(),
                ]);

            $ingestionMessage->forceFill([
                'status' => IngestionStatus::Duplicate,
            ]);
        }

        return [
            'message' => $ingestionMessage,
            'should_continue' => false,
        ];
    }
}
