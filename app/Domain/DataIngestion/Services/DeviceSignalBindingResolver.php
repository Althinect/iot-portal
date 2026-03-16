<?php

declare(strict_types=1);

namespace App\Domain\DataIngestion\Services;

use App\Domain\DataIngestion\DTO\IncomingTelemetryEnvelope;
use App\Domain\DataIngestion\Models\DeviceSignalBinding;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceSchema\Enums\ParameterDataType;
use App\Domain\DeviceSchema\Models\ParameterDefinition;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class DeviceSignalBindingResolver
{
    /**
     * @var array<string, array<int, DeviceSignalBinding>>
     */
    private array $bindingRegistry = [];

    private ?Carbon $lastRegistryRefreshAt = null;

    public function supportsTopic(string $mqttTopic): bool
    {
        if ($this->shouldRefreshRegistry()) {
            $this->refreshRegistry();
        }

        return array_key_exists($mqttTopic, $this->bindingRegistry);
    }

    /**
     * @return Collection<int, IncomingTelemetryEnvelope>
     */
    public function expand(IncomingTelemetryEnvelope $envelope): Collection
    {
        if ($this->shouldRefreshRegistry()) {
            $this->refreshRegistry();
        }

        /** @var Collection<int, DeviceSignalBinding> $bindings */
        $bindings = collect($this->bindingRegistry[$envelope->mqttTopic] ?? []);

        if ($bindings->isEmpty()) {
            return collect();
        }

        return $bindings
            ->groupBy(function (DeviceSignalBinding $binding): string {
                $parameterDefinition = $binding->parameterDefinition;
                $topicId = $parameterDefinition instanceof ParameterDefinition
                    ? $parameterDefinition->schema_version_topic_id
                    : 0;

                return $binding->device_id.'::'.$topicId;
            })
            ->map(function (Collection $bindingGroup) use ($envelope): ?IncomingTelemetryEnvelope {
                /** @var DeviceSignalBinding|null $firstBinding */
                $firstBinding = $bindingGroup->first();

                if (! $firstBinding instanceof DeviceSignalBinding) {
                    return null;
                }

                $device = $firstBinding->device;
                $parameterDefinition = $firstBinding->parameterDefinition;
                $topic = $parameterDefinition?->topic;

                if (
                    ! $device instanceof Device
                    || ! $parameterDefinition instanceof ParameterDefinition
                    || ! $topic instanceof SchemaVersionTopic
                ) {
                    return null;
                }

                $payload = [
                    '_meta' => array_filter([
                        'binding_mode' => 'device_signal',
                        'source_adapter' => $firstBinding->source_adapter,
                        'source_subject' => $envelope->sourceSubject,
                        'source_topic' => $envelope->mqttTopic,
                    ]),
                ];

                $mappedValueCount = 0;

                foreach ($bindingGroup as $binding) {
                    $extracted = $binding->extractSourceValue($envelope->payload);

                    if (! $extracted['found']) {
                        continue;
                    }

                    $parameterDefinition = $binding->parameterDefinition;

                    if (! $parameterDefinition instanceof ParameterDefinition) {
                        continue;
                    }

                    $payload = $parameterDefinition->placeValue(
                        $payload,
                        $this->coerceValueForParameter($extracted['value'], $parameterDefinition),
                    );

                    $mappedValueCount++;
                }

                if ($mappedValueCount === 0) {
                    return null;
                }

                $resolvedTopic = $topic->resolvedTopic($device);

                return new IncomingTelemetryEnvelope(
                    sourceSubject: $envelope->sourceSubject.'#'.$resolvedTopic,
                    mqttTopic: $resolvedTopic,
                    payload: $payload,
                    deviceUuid: $device->uuid,
                    deviceExternalId: $device->external_id,
                    messageId: $envelope->messageId,
                    receivedAt: $envelope->receivedAt,
                );
            })
            ->filter(fn (?IncomingTelemetryEnvelope $envelope): bool => $envelope instanceof IncomingTelemetryEnvelope)
            ->values();
    }

    public function refreshRegistry(): void
    {
        $this->bindingRegistry = [];

        $bindings = DeviceSignalBinding::query()
            ->with([
                'device.deviceType',
                'device.schemaVersion.topics',
                'parameterDefinition.topic',
            ])
            ->where('is_active', true)
            ->orderBy('source_topic')
            ->orderBy('sequence')
            ->get();

        foreach ($bindings as $binding) {
            $sourceTopic = trim((string) $binding->source_topic);

            if ($sourceTopic === '') {
                continue;
            }

            $this->bindingRegistry[$sourceTopic] ??= [];
            $this->bindingRegistry[$sourceTopic][] = $binding;
        }

        $this->lastRegistryRefreshAt = now();
    }

    private function shouldRefreshRegistry(): bool
    {
        if (! $this->lastRegistryRefreshAt instanceof Carbon) {
            return true;
        }

        $ttl = config('ingestion.registry_ttl_seconds', 30);
        $ttlSeconds = is_numeric($ttl) ? (int) $ttl : 30;

        return $this->lastRegistryRefreshAt->diffInSeconds(now()) > $ttlSeconds;
    }

    private function coerceValueForParameter(mixed $value, ParameterDefinition $parameterDefinition): mixed
    {
        return match ($parameterDefinition->type) {
            ParameterDataType::Integer => $this->coerceInteger($value),
            ParameterDataType::Decimal => $this->coerceDecimal($value),
            ParameterDataType::Boolean => $this->coerceBoolean($value),
            ParameterDataType::String => $this->coerceString($value),
            ParameterDataType::Json => $value,
        };
    }

    private function coerceInteger(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return is_numeric($value) ? (int) $value : $value;
    }

    private function coerceDecimal(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }

        return is_numeric($value) ? (float) $value : $value;
    }

    private function coerceBoolean(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === 1 || $value === '1') {
            return true;
        }

        if ($value === 0 || $value === '0') {
            return false;
        }

        return $value;
    }

    private function coerceString(mixed $value): mixed
    {
        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        return $value;
    }
}
