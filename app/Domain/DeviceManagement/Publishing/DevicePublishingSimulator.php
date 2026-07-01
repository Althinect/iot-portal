<?php

declare(strict_types=1);

namespace App\Domain\DeviceManagement\Publishing;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Publishing\Nats\NatsPublisher;
use App\Domain\DeviceManagement\Publishing\Nats\NatsPublisherFactory;
use App\Domain\DeviceManagement\Services\DevicePresenceService;
use App\Domain\DeviceManagement\ValueObjects\Protocol\MqttProtocolConfig;
use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
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
     * @param  (callable(int $iteration, string $mqttTopic, array<string, mixed> $payload, DeviceChannel $channel): void)|null  $onBeforePublish
     * @param  (callable(int $iteration, string $mqttTopic, \Throwable $exception, DeviceChannel $channel): void)|null  $onPublishFailed
     */
    public function simulate(
        Device $device,
        int $count = 10,
        int $intervalSeconds = 1,
        ?int $deviceChannelId = null,
        ?string $host = null,
        ?int $port = null,
        ?callable $onBeforePublish = null,
        ?callable $onPublishFailed = null,
    ): void {
        $channels = $this->resolvePublishChannels($device, $deviceChannelId);

        if ($channels->isEmpty()) {
            return;
        }

        $publisher = $this->createPublisher($host, $port);
        $counterState = [];

        for ($i = 1; $i <= $count; $i++) {
            $this->publishTopics(
                device: $device,
                publisher: $publisher,
                iteration: $i,
                deviceChannelId: $deviceChannelId,
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
     * @param  (callable(int $iteration, string $mqttTopic, array<string, mixed> $payload, DeviceChannel $channel): void)|null  $onBeforePublish
     * @param  (callable(int $iteration, string $mqttTopic, \Throwable $exception, DeviceChannel $channel): void)|null  $onPublishFailed
     */
    public function publishTopics(
        Device $device,
        NatsPublisher $publisher,
        int $iteration = 1,
        ?int $deviceChannelId = null,
        array &$counterState = [],
        ?callable $onBeforePublish = null,
        ?callable $onPublishFailed = null,
    ): int {
        $publishedMessages = 0;
        $channels = $this->resolvePublishChannels($device, $deviceChannelId);

        foreach ($channels as $channel) {
            $payload = $this->generateRandomPayload($channel, $counterState);
            $mqttTopic = $this->resolveTopicWithExternalId($device, $channel);

            if ($onBeforePublish !== null) {
                $onBeforePublish($iteration, $mqttTopic, $payload, $channel);
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
                    $onPublishFailed($iteration, $mqttTopic, $exception, $channel);
                }
            }
        }

        return $publishedMessages;
    }

    /**
     * @return Collection<int, DeviceChannel>
     */
    public function resolvePublishChannels(Device $device, ?int $deviceChannelId = null): Collection
    {
        $device->loadMissing('profileVersion.channels.parameters');

        return $device->profileVersion?->channels
            ?->filter(fn (DeviceChannel $channel): bool => $channel->isPublish())
            ->when(
                $deviceChannelId !== null,
                fn (Collection $channels): Collection => $channels->where('id', $deviceChannelId),
            )
            ->sortBy('sequence')
            ->values()
            ?? collect();
    }

    /**
     * @param  array<string, float>  $counterState
     * @return array<string, mixed>
     */
    private function generateRandomPayload(DeviceChannel $channel, array &$counterState): array
    {
        $channel->loadMissing('parameters');

        $payload = [];

        $channel->parameters
            ->where('is_active', true)
            ->sortBy('sequence')
            ->each(function (ProfileParameterDefinition $parameter) use (&$payload, &$counterState): void {
                $value = $this->generateRandomValue($parameter, $counterState);
                $payload = $parameter->placeValue($payload, $value);
            });

        return $payload;
    }

    /**
     * @param  array<string, float>  $counterState
     */
    private function generateRandomValue(ProfileParameterDefinition $parameter, array &$counterState): mixed
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

    private function resolveCounterStateKey(ProfileParameterDefinition $parameter): string
    {
        return $parameter->device_channel_id.':'.$parameter->key;
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

    private function resolveTopicWithExternalId(Device $device, DeviceChannel $channel): string
    {
        $protocolConfig = $device->profileVersion?->protocol_config;
        $baseTopic = $protocolConfig instanceof MqttProtocolConfig ? $protocolConfig->getBaseTopic() : 'device';
        $identifier = $device->external_id ?: $device->uuid;

        return trim($baseTopic, '/').'/'.$identifier.'/'.$channel->address;
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
