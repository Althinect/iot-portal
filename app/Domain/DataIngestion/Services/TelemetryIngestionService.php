<?php

declare(strict_types=1);

namespace App\Domain\DataIngestion\Services;

use App\Domain\DataIngestion\Contracts\HotStateStore;
use App\Domain\DataIngestion\DTO\IncomingTelemetryEnvelope;
use App\Domain\DataIngestion\Enums\IngestionStage;
use App\Domain\DataIngestion\Enums\IngestionStatus;
use App\Domain\DataIngestion\Models\IngestionMessage;
use App\Domain\DataIngestion\Models\IngestionStageLog;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use Illuminate\Support\Collection;
use Laravel\Pennant\Feature;

class TelemetryIngestionService
{
    public function __construct(
        private readonly DeviceTelemetryTopicResolver $topicResolver,
        private readonly TelemetryValidationService $validationService,
        private readonly TelemetryMutationService $mutationService,
        private readonly TelemetryDerivationService $derivationService,
        private readonly TelemetryPersistenceService $persistenceService,
        private readonly HotStateStore $hotStateStore,
        private readonly TelemetryAnalyticsPublishService $analyticsPublishService,
    ) {}

    public function ingest(IncomingTelemetryEnvelope $envelope): ?IngestionMessage
    {
        if (! Feature::active('ingestion.pipeline.enabled')) {
            return null;
        }

        $featureDriver = Feature::value('ingestion.pipeline.driver');
        $driver = is_string($featureDriver) ? $featureDriver : 'laravel';

        if ($driver !== 'laravel') {
            return null;
        }

        $ingestionMessage = IngestionMessage::query()->firstOrCreate(
            ['source_deduplication_key' => $envelope->deduplicationKey()],
            [
                'source_subject' => $envelope->sourceSubject,
                'source_protocol' => 'mqtt',
                'source_message_id' => $envelope->messageId,
                'raw_payload' => $envelope->payload,
                'status' => IngestionStatus::Queued,
                'received_at' => $envelope->resolveReceivedAt(),
            ],
        );

        if (! $ingestionMessage->wasRecentlyCreated) {
            $status = $ingestionMessage->status;
            $isDuplicate = $status === IngestionStatus::Duplicate->value;

            if (! $isDuplicate) {
                $ingestionMessage->update([
                    'status' => IngestionStatus::Duplicate,
                ]);
            }

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
        $derivedParameters = $schemaVersion->derivedParameters()->get();

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
            $this->persistenceService->persist(
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

            if ($device->is_active) {
                $this->analyticsPublishService->publishInvalid($device, $topic, $validationResult['validation_errors'], $ingestionMessage);
            }

            return $ingestionMessage;
        }

        if (! $device->is_active) {
            $this->persistenceService->persist(
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

        $publishStartedAt = microtime(true);
        $publishErrors = [];
        $publishOutput = [
            'hot_state_written' => false,
            'analytics_published' => false,
        ];

        try {
            $this->hotStateStore->store($device, $topic, $derivationResult['final_values'], $ingestionMessage);
            $publishOutput['hot_state_written'] = true;
        } catch (\Throwable $exception) {
            report($exception);
            $publishErrors['hot_state'] = $exception->getMessage();
        }

        try {
            $this->analyticsPublishService->publishTelemetry($device, $topic, $derivationResult['final_values'], $ingestionMessage);
            $publishOutput['analytics_published'] = true;
        } catch (\Throwable $exception) {
            report($exception);
            $publishErrors['analytics_publish'] = $exception->getMessage();
        }

        if ($publishErrors !== []) {
            $telemetryLog->update([
                'processing_state' => 'publish_failed',
            ]);

            $this->logStage(
                ingestionMessage: $ingestionMessage,
                stage: IngestionStage::Publish,
                status: IngestionStatus::FailedTerminal,
                startedAt: $publishStartedAt,
                outputSnapshot: $publishOutput,
                errors: $publishErrors,
            );

            $this->finalizeMessage($ingestionMessage, [
                ...$messageContext,
                'status' => IngestionStatus::FailedTerminal,
                'error_summary' => [
                    'reason' => 'publish_failed',
                    'errors' => $publishErrors,
                ],
            ]);

            return $ingestionMessage;
        }

        $this->logStage(
            ingestionMessage: $ingestionMessage,
            stage: IngestionStage::Publish,
            status: IngestionStatus::Completed,
            startedAt: $publishStartedAt,
            outputSnapshot: $publishOutput,
        );

        $this->finalizeMessage($ingestionMessage, [
            ...$messageContext,
            'status' => IngestionStatus::Completed,
        ]);

        return $ingestionMessage;
    }

    /**
     * @return Collection<int, \App\Domain\DeviceSchema\Models\ParameterDefinition>
     */
    private function resolveActiveParameters(SchemaVersionTopic $topic): Collection
    {
        return $topic->parameters()
            ->where('is_active', true)
            ->orderBy('sequence')
            ->get();
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
}
