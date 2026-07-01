<?php

declare(strict_types=1);

namespace App\Domain\DeviceProfile\DTO;

use App\Domain\Telemetry\Enums\ValidationStatus;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;

/**
 * Outcome of the full profile-driven ingestion pipeline. Carries the same
 * value shapes (transformed_values, mutated_values, validation_errors) the
 * legacy pipeline produced, plus the persisted telemetry log.
 */
final readonly class IngestionOutcome
{
    /**
     * @param  array<string, mixed>  $extractedValues
     * @param  array<string, mixed>|null  $mutatedValues
     * @param  array<string, mixed>  $finalValues
     * @param  array<string, array{error_code: string|null, is_critical: bool}>  $validationErrors
     */
    public function __construct(
        public string $processingState,
        public ValidationStatus $validationStatus,
        public array $extractedValues,
        public ?array $mutatedValues,
        public array $finalValues,
        public array $validationErrors,
        public ?DeviceTelemetryLog $telemetryLog,
    ) {}
}
