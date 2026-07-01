<?php

declare(strict_types=1);

namespace App\Domain\DeviceControl\Services;

use App\Domain\DeviceProfile\Enums\ControlWidgetType;
use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;

class CommandPayloadResolver
{
    /**
     * @param  array<string, mixed>  $controlValues
     * @return array{payload: array<string, mixed>, errors: array<string, string>}
     */
    public function resolveFromControls(DeviceChannel $channel, array $controlValues): array
    {
        $channel->loadMissing('parameters');

        $payload = [];
        $errors = [];

        foreach ($channel->parameters->where('is_active', true)->sortBy('sequence') as $parameter) {
            $widgetType = $parameter->resolvedWidgetType();
            $defaultValue = $parameter->resolvedDefaultValue();
            $hasControlValue = array_key_exists($parameter->key, $controlValues);

            if (! $hasControlValue && $widgetType === ControlWidgetType::Button) {
                continue;
            }

            $rawValue = $hasControlValue
                ? $controlValues[$parameter->key]
                : $defaultValue;

            if (
                $widgetType === ControlWidgetType::Button
                && ! $parameter->required
                && $rawValue === $defaultValue
            ) {
                continue;
            }

            $value = $this->castForType($parameter, $rawValue);
            $validation = $parameter->validateValue($value);

            if ($validation['is_valid'] === false) {
                $errors[$parameter->key] = $validation['error_code'] ?? 'Invalid value.';

                continue;
            }

            $payload = $parameter->placeValue($payload, $value);
        }

        return [
            'payload' => $payload,
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    public function validatePayload(DeviceChannel $channel, array $payload): array
    {
        $channel->loadMissing('parameters');

        $errors = [];

        foreach ($channel->parameters->where('is_active', true)->sortBy('sequence') as $parameter) {
            $value = $parameter->extractValue($payload);
            $castValue = $this->castForType($parameter, $value);
            $validation = $parameter->validateValue($castValue);

            if ($validation['is_valid'] === false) {
                $errors[$parameter->key] = $validation['error_code'] ?? 'Invalid value.';
            }
        }

        return $errors;
    }

    private function castForType(ProfileParameterDefinition $parameter, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($parameter->type) {
            ParameterDataType::Integer => is_numeric($value) ? (int) $value : $value,
            ParameterDataType::Decimal => is_numeric($value) ? (float) $value : $value,
            ParameterDataType::Boolean => $this->castBoolean($value),
            ParameterDataType::Json => is_array($value)
                ? $value
                : (is_string($value) ? (json_decode($value, true) ?? $value) : $value),
            ParameterDataType::String => is_scalar($value) ? (string) $value : $value,
        };
    }

    private function castBoolean(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value;
        }

        if (in_array($value, ['1', 1, 'true', 'on', 'yes'], true)) {
            return true;
        }

        if (in_array($value, ['0', 0, 'false', 'off', 'no'], true)) {
            return false;
        }

        return $value;
    }
}
