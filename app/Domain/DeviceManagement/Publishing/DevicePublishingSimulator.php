<?php

declare(strict_types=1);

namespace App\Domain\DeviceManagement\Publishing;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Publishing\Nats\NatsPublisher;
use App\Domain\DeviceManagement\Publishing\Nats\NatsPublisherFactory;
use App\Domain\DeviceManagement\Services\DevicePresenceService;
use App\Domain\DeviceSchema\Enums\ParameterDataType;
use App\Domain\DeviceSchema\Models\ParameterDefinition;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Events\TelemetryIncoming;
use Illuminate\Support\Collection;

final readonly class DevicePublishingSimulator
{
    public function __construct(
        private NatsPublisherFactory $publisherFactory,
        private DevicePresenceService $presenceService,
    ) {}

    /**
     * Simulate device -> platform publishing.
     *
     * @param  (callable(int $iteration, string $mqttTopic, array<string, mixed> $payload, SchemaVersionTopic $topic): void)|null  $onBeforePublish
     * @param  (callable(int $iteration, string $mqttTopic, \Throwable $exception, SchemaVersionTopic $topic): void)|null  $onPublishFailed
     */
    public function simulate(
        Device $device,
        int $count = 10,
        int $intervalSeconds = 1,
        ?int $schemaVersionTopicId = null,
        ?string $host = null,
        ?int $port = null,
        ?callable $onBeforePublish = null,
        ?callable $onPublishFailed = null,
    ): void {
        $topics = $this->resolvePublishTopics($device, $schemaVersionTopicId);

        if ($topics->isEmpty()) {
            return;
        }

        $publisher = $this->createPublisher($host, $port);
        $counterState = [];

        for ($i = 1; $i <= $count; $i++) {
            $this->publishTopics(
                device: $device,
                publisher: $publisher,
                iteration: $i,
                schemaVersionTopicId: $schemaVersionTopicId,
                counterState: $counterState,
                onBeforePublish: $onBeforePublish,
                onPublishFailed: $onPublishFailed,
            );

            if ($i < $count && $intervalSeconds > 0) {
                sleep($intervalSeconds);
            }
        }
    }

    public function createPublisher(?string $host = null, ?int $port = null): NatsPublisher
    {
        return $this->publisherFactory->make(
            $this->resolveHost($host),
            $this->resolvePort($port),
        );
    }

    /**
     * @param  array<string, float>  $counterState
     * @param  (callable(int $iteration, string $mqttTopic, array<string, mixed> $payload, SchemaVersionTopic $topic): void)|null  $onBeforePublish
     * @param  (callable(int $iteration, string $mqttTopic, \Throwable $exception, SchemaVersionTopic $topic): void)|null  $onPublishFailed
     */
    public function publishTopics(
        Device $device,
        NatsPublisher $publisher,
        int $iteration = 1,
        ?int $schemaVersionTopicId = null,
        array &$counterState = [],
        ?callable $onBeforePublish = null,
        ?callable $onPublishFailed = null,
    ): int {
        $publishedMessages = 0;
        $topics = $this->resolvePublishTopics($device, $schemaVersionTopicId);

        foreach ($topics as $topic) {
            $payload = $this->generateRandomPayload($topic, $counterState);
            $mqttTopic = $this->resolveTopicWithExternalId($device, $topic);

            if ($onBeforePublish !== null) {
                $onBeforePublish($iteration, $mqttTopic, $payload, $topic);
            }

            $natsSubject = str_replace('/', '.', $mqttTopic);
            $encodedPayload = json_encode($payload);
            $encodedPayload = is_string($encodedPayload) ? $encodedPayload : '{}';

            try {
                $publisher->publish($natsSubject, $encodedPayload);
                $this->presenceService->markOnline($device);

                event(new TelemetryIncoming(
                    topic: $mqttTopic,
                    deviceUuid: $device->uuid,
                    deviceExternalId: $device->external_id,
                    payload: $payload,
                ));

                $publishedMessages++;
            } catch (\Throwable $exception) {
                report($exception);

                if ($onPublishFailed !== null) {
                    $onPublishFailed($iteration, $mqttTopic, $exception, $topic);
                }
            }
        }

        return $publishedMessages;
    }

    /**
     * @return Collection<int, SchemaVersionTopic>
     */
    public function resolvePublishTopics(Device $device, ?int $schemaVersionTopicId = null): Collection
    {
        $device->loadMissing('deviceType', 'schemaVersion.topics.parameters');

        return $device->schemaVersion?->topics
            ?->filter(fn (SchemaVersionTopic $topic): bool => $topic->isPublish())
            ->when(
                $schemaVersionTopicId !== null,
                fn (Collection $topics): Collection => $topics->where('id', $schemaVersionTopicId),
            )
            ->sortBy('sequence')
            ->values()
            ?? collect();
    }

    /**
     * @param  array<string, float>  $counterState
     * @return array<string, mixed>
     */
    private function generateRandomPayload(SchemaVersionTopic $topic, array &$counterState): array
    {
        $topic->loadMissing('parameters');

        $payload = [];

        $topic->parameters
            ->where('is_active', true)
            ->sortBy('sequence')
            ->each(function (ParameterDefinition $parameter) use (&$payload, &$counterState): void {
                $value = $this->generateRandomValue($parameter, $counterState);
                $payload = $parameter->placeValue($payload, $value);
            });

        return $payload;
    }

    /**
     * @param  array<string, float>  $counterState
     */
    private function generateRandomValue(ParameterDefinition $parameter, array &$counterState): mixed
    {
        $rules = $parameter->resolvedValidationRules();
        $type = $parameter->type;
        $category = is_string($rules['category'] ?? null)
            ? strtolower((string) $rules['category'])
            : null;
        $enumValues = $this->resolveEnumValues($rules);

        if ($category === 'enum' && $enumValues !== []) {
            return $enumValues[array_rand($enumValues)];
        }

        if ($category === 'counter') {
            $key = $this->resolveCounterStateKey($parameter);
            $incrementRange = $this->resolveCounterIncrementRange($type, $rules);
            $counterMin = isset($rules['min']) && is_numeric($rules['min'])
                ? (float) $rules['min']
                : (is_numeric($parameter->default_value) ? (float) $parameter->default_value : 0.0);
            $counterMax = isset($rules['max']) && is_numeric($rules['max'])
                ? (float) $rules['max']
                : PHP_FLOAT_MAX;

            if ($counterMax < $counterMin) {
                $counterMax = $counterMin;
            }

            if (! array_key_exists($key, $counterState)) {
                $counterState[$key] = is_numeric($parameter->default_value)
                    ? (float) $parameter->default_value
                    : $counterMin;
            }

            $nextValue = (float) $counterState[$key] + $this->randomFloat($incrementRange['min'], $incrementRange['max']);
            $counterState[$key] = min($nextValue, $counterMax);

            return $type === ParameterDataType::Integer
                ? (int) round($counterState[$key])
                : round((float) $counterState[$key], 3);
        }

        if ($type === ParameterDataType::String && $enumValues !== []) {
            return $enumValues[array_rand($enumValues)];
        }

        return match ($type) {
            ParameterDataType::Integer => (int) round($this->randomFloat(...$this->resolveNumericBounds($type, $rules))),
            ParameterDataType::Decimal => round($this->randomFloat(...$this->resolveNumericBounds($type, $rules)), 3),
            ParameterDataType::Boolean => (bool) rand(0, 1),
            ParameterDataType::String => 'Value_'.rand(100, 999),
            ParameterDataType::Json => ['v' => rand(1, 5)],
        };
    }

    private function resolveCounterStateKey(ParameterDefinition $parameter): string
    {
        return $parameter->schema_version_topic_id.':'.$parameter->key;
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<int, int|float>
     */
    private function resolveNumericBounds(ParameterDataType $type, array $rules): array
    {
        $range = $this->resolveNumericRange($type, $rules);

        return [$range['min'], $range['max']];
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array{min: float, max: float}
     */
    private function resolveNumericRange(ParameterDataType $type, array $rules): array
    {
        $defaultMax = $type === ParameterDataType::Integer ? 100.0 : 100.0;

        $min = isset($rules['min']) && is_numeric($rules['min']) ? (float) $rules['min'] : 0.0;
        $max = isset($rules['max']) && is_numeric($rules['max'])
            ? (float) $rules['max']
            : max($min + 1.0, $defaultMax);

        if ($max < $min) {
            $max = $min;
        }

        return [
            'min' => $min,
            'max' => $max,
        ];
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array{min: float, max: float}
     */
    private function resolveCounterIncrementRange(ParameterDataType $type, array $rules): array
    {
        $defaultMin = $type === ParameterDataType::Integer ? 1.0 : 0.01;
        $defaultMax = $type === ParameterDataType::Integer ? 5.0 : 0.5;

        $min = isset($rules['increment_min']) && is_numeric($rules['increment_min'])
            ? (float) $rules['increment_min']
            : $defaultMin;
        $max = isset($rules['increment_max']) && is_numeric($rules['increment_max'])
            ? (float) $rules['increment_max']
            : $defaultMax;

        if ($max < $min) {
            $max = $min;
        }

        return [
            'min' => $min,
            'max' => $max,
        ];
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<int, string|int|float>
     */
    private function resolveEnumValues(array $rules): array
    {
        if (! is_array($rules['enum'] ?? null)) {
            return [];
        }

        return array_values(array_filter(
            $rules['enum'],
            fn (mixed $value): bool => is_string($value) || is_int($value) || is_float($value),
        ));
    }

    private function randomFloat(float $min, float $max): float
    {
        if ($max <= $min) {
            return $min;
        }

        return $min + (lcg_value() * ($max - $min));
    }

    private function resolveTopicWithExternalId(Device $device, SchemaVersionTopic $topic): string
    {
        $baseTopic = $device->deviceType?->protocol_config?->getBaseTopic() ?? 'device';
        $identifier = $device->external_id ?: $device->uuid;

        return trim($baseTopic, '/').'/'.$identifier.'/'.$topic->suffix;
    }

    private function resolveHost(?string $host): string
    {
        if (is_string($host) && trim($host) !== '') {
            return trim($host);
        }

        $configuredHost = config('iot.nats.host', '127.0.0.1');

        return is_string($configuredHost) && trim($configuredHost) !== ''
            ? trim($configuredHost)
            : '127.0.0.1';
    }

    private function resolvePort(?int $port): int
    {
        if (is_int($port) && $port > 0) {
            return $port;
        }

        $configuredPort = config('iot.nats.port', 4223);

        return is_numeric($configuredPort) ? (int) $configuredPort : 4223;
    }
}
