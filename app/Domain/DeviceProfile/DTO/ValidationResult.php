<?php

declare(strict_types=1);

namespace App\Domain\DeviceProfile\DTO;

use App\Domain\Telemetry\Enums\ValidationStatus;

/**
 * Result of validating an inbound payload against a channel's parameters.
 */
final readonly class ValidationResult
{
    /**
     * @param  array<string, mixed>  $extractedValues
     * @param  array<string, array{error_code: string|null, is_critical: bool}>  $validationErrors
     */
    public function __construct(
        public array $extractedValues,
        public array $validationErrors,
        public ValidationStatus $status,
    ) {}

    public function passes(): bool
    {
        return $this->validationErrors === [];
    }

    public function isInvalid(): bool
    {
        return $this->status === ValidationStatus::Invalid;
    }
}
