<?php

declare(strict_types=1);

namespace App\Domain\DeviceProfile\Services;

use App\Domain\DeviceProfile\DTO\ChannelDefinition;
use App\Domain\DeviceProfile\DTO\ValidationResult;
use App\Domain\Telemetry\Enums\ValidationStatus;

class ProfileTelemetryValidator
{
    /**
     * Validate an inbound payload against a channel's active parameters.
     *
     * @param  array<string, mixed>  $payload
     */
    public function validate(array $payload, ChannelDefinition $channel): ValidationResult
    {
        $extractedValues = [];
        $validationErrors = [];

        $hasInvalid = false;
        $hasCriticalInvalid = false;

        foreach ($channel->activeParameters() as $parameter) {
            $value = $parameter->extractValue($payload);
            $extractedValues[$parameter->key] = $value;

            $validation = $parameter->validateValue($value);

            if ($validation['is_valid'] === true) {
                continue;
            }

            $hasInvalid = true;
            $isCritical = $validation['is_critical'] === true;

            if ($isCritical) {
                $hasCriticalInvalid = true;
            }

            $validationErrors[$parameter->key] = [
                'error_code' => $validation['error_code'],
                'is_critical' => $isCritical,
            ];
        }

        $status = match (true) {
            $hasCriticalInvalid => ValidationStatus::Invalid,
            $hasInvalid => ValidationStatus::Warning,
            default => ValidationStatus::Valid,
        };

        return new ValidationResult(
            extractedValues: $extractedValues,
            validationErrors: $validationErrors,
            status: $status,
        );
    }
}
