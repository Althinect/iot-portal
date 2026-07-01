<?php

declare(strict_types=1);

namespace App\Domain\DeviceProfile\Services;

use App\Domain\DeviceManagement\ValueObjects\Protocol\ProtocolConfigInterface;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Models\ProfileDerivedParameterDefinition;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use BackedEnum;
use JsonException;
use UnitEnum;

final class DeviceProfileVersionConfigurationHasher
{
    /**
     * @throws JsonException
     */
    public function signature(DeviceProfileVersion $version): string
    {
        return hash(
            'sha256',
            json_encode(
                $this->payload($version),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            )
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(DeviceProfileVersion $version): array
    {
        $version->loadMissing(['channels.parameters', 'derivedParameters']);

        return [
            'protocol' => $this->normalizedValue($version->protocol),
            'protocol_config' => $this->normalizedValue($version->protocol_config),
            'firmware_template' => $version->firmware_template,
            'ingestion_config' => $this->normalizedValue($version->ingestion_config),
            'virtual_standard_profile' => $this->normalizedValue($version->virtual_standard_profile),
            'channels' => $version->channels
                ->map(fn (DeviceChannel $channel): array => $this->channelPayload($channel))
                ->sortBy(fn (array $channel): string => (string) $channel['key'])
                ->values()
                ->all(),
            'derived_parameters' => $version->derivedParameters
                ->map(fn (ProfileDerivedParameterDefinition $parameter): array => $this->derivedParameterPayload($parameter))
                ->sortBy(fn (array $parameter): string => (string) $parameter['key'])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function channelPayload(DeviceChannel $channel): array
    {
        $channel->loadMissing('parameters');

        return [
            'key' => $channel->key,
            'label' => $channel->label,
            'direction' => $this->normalizedValue($channel->direction),
            'purpose' => $this->normalizedValue($channel->purpose),
            'transport' => $this->normalizedValue($channel->transport),
            'address' => $channel->address,
            'http_method' => $channel->http_method,
            'description' => $channel->description,
            'qos' => $channel->qos,
            'retain' => $channel->retain,
            'sequence' => $channel->sequence,
            'options' => $this->normalizedValue($channel->options),
            'parameters' => $channel->parameters
                ->map(fn (ProfileParameterDefinition $parameter): array => $this->parameterPayload($parameter))
                ->sortBy(fn (array $parameter): string => (string) $parameter['key'])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parameterPayload(ProfileParameterDefinition $parameter): array
    {
        return [
            'key' => $parameter->key,
            'label' => $parameter->label,
            'json_path' => $parameter->json_path,
            'type' => $this->normalizedValue($parameter->type),
            'category' => $this->normalizedValue($parameter->category),
            'unit' => $parameter->unit,
            'required' => $parameter->required,
            'is_critical' => $parameter->is_critical,
            'validation_rules' => $this->normalizedValue($parameter->validation_rules),
            'control_ui' => $this->normalizedValue($parameter->control_ui),
            'validation_error_code' => $parameter->validation_error_code,
            'mutation_expression' => $this->normalizedValue($parameter->mutation_expression),
            'sequence' => $parameter->sequence,
            'is_active' => $parameter->is_active,
            'default_value' => $this->normalizedValue($parameter->default_value),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function derivedParameterPayload(ProfileDerivedParameterDefinition $parameter): array
    {
        return [
            'key' => $parameter->key,
            'label' => $parameter->label,
            'data_type' => $this->normalizedValue($parameter->data_type),
            'unit' => $parameter->unit,
            'expression' => $this->normalizedValue($parameter->expression),
            'dependencies' => $this->normalizedValue($parameter->dependencies),
            'json_path' => $parameter->json_path,
        ];
    }

    private function normalizedValue(mixed $value): mixed
    {
        if ($value instanceof ProtocolConfigInterface) {
            return $this->normalizedValue($value->toArray());
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalizedValue($item), $value);
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizedValue($item);
        }

        return $value;
    }
}
