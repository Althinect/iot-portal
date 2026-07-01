<?php

declare(strict_types=1);

namespace App\Domain\DeviceProfile\DTO;

use App\Domain\DeviceManagement\ValueObjects\Protocol\HttpProtocolConfig;
use App\Domain\DeviceManagement\ValueObjects\Protocol\MqttProtocolConfig;
use App\Domain\DeviceManagement\ValueObjects\Protocol\ProtocolConfigInterface;
use App\Domain\DeviceProfile\Enums\ChannelDirection;
use App\Domain\DeviceProfile\Enums\ChannelPurpose;
use App\Domain\DeviceProfile\Enums\ChannelTransport;
use App\Domain\DeviceProfile\Enums\ControlWidgetType;

/**
 * Immutable channel contract DTO. A channel is the protocol-neutral event
 * boundary that replaces the MQTT-specific "topic". It owns its scoped
 * parameter definitions and knows how to resolve its transport address and
 * build command/publish payload templates.
 */
final readonly class ChannelDefinition
{
    /**
     * @param  array<int, ParameterDefinitionData>  $parameters
     * @param  array<string, mixed>|null  $options
     */
    public function __construct(
        public int $id,
        public string $key,
        public string $label,
        public ChannelDirection $direction,
        public ?ChannelPurpose $purpose,
        public ChannelTransport $transport,
        public string $address,
        public ?string $httpMethod,
        public ?string $description,
        public int $qos,
        public bool $retain,
        public int $sequence,
        public ?array $options,
        public array $parameters,
    ) {}

    public function isPublish(): bool
    {
        return $this->direction === ChannelDirection::Publish;
    }

    public function isSubscribe(): bool
    {
        return $this->direction === ChannelDirection::Subscribe;
    }

    public function resolvedPurpose(): ChannelPurpose
    {
        if ($this->purpose instanceof ChannelPurpose) {
            return $this->purpose;
        }

        $address = strtolower($this->address);

        return match (true) {
            $this->isSubscribe() => ChannelPurpose::Command,
            str_contains($address, 'ack') => ChannelPurpose::Ack,
            $this->retain || in_array($address, ['state', 'status'], true) => ChannelPurpose::State,
            default => ChannelPurpose::Telemetry,
        };
    }

    public function isPurposeCommand(): bool
    {
        return $this->resolvedPurpose() === ChannelPurpose::Command;
    }

    public function isPurposeTelemetry(): bool
    {
        return $this->resolvedPurpose() === ChannelPurpose::Telemetry;
    }

    public function isPurposeState(): bool
    {
        return $this->resolvedPurpose() === ChannelPurpose::State;
    }

    public function isPurposeAck(): bool
    {
        return $this->resolvedPurpose() === ChannelPurpose::Ack;
    }

    /**
     * Resolve the full transport address for a given device identifier and
     * protocol configuration.
     *
     * MQTT:  {baseTopic}/{identifier}/{address}
     * HTTP:  {baseUrl}/devices/{identifier}{address} (unless the address
     *        already carries a {device} placeholder, in which case it is
     *        substituted directly against the protocol base).
     */
    public function resolvedAddress(string $deviceIdentifier, ?ProtocolConfigInterface $protocolConfig): string
    {
        if (str_contains($this->address, '{device}')) {
            $base = match (true) {
                $protocolConfig instanceof MqttProtocolConfig => rtrim($protocolConfig->getBaseTopic(), '/'),
                $protocolConfig instanceof HttpProtocolConfig => rtrim($protocolConfig->baseUrl, '/'),
                default => '',
            };
            $substituted = str_replace('{device}', $deviceIdentifier, $this->address);

            return $base !== '' ? "{$base}/".ltrim($substituted, '/') : $substituted;
        }

        return match (true) {
            $protocolConfig instanceof MqttProtocolConfig => rtrim($protocolConfig->getBaseTopic(), '/')."/{$deviceIdentifier}/{$this->address}",
            $protocolConfig instanceof HttpProtocolConfig => rtrim($protocolConfig->baseUrl, '/')."/devices/{$deviceIdentifier}".($this->address !== '' && ! str_starts_with($this->address, '/') ? "/{$this->address}" : $this->address),
            default => $this->address,
        };
    }

    public function resolvedLookupKey(string $deviceIdentifier, ?ProtocolConfigInterface $protocolConfig): string
    {
        $resolvedAddress = $this->resolvedAddress($deviceIdentifier, $protocolConfig);

        if ($resolvedAddress === '' || $this->transport !== ChannelTransport::Http) {
            return $resolvedAddress;
        }

        $method = $this->resolvedHttpMethod($protocolConfig);

        return $method !== '' ? "{$method} {$resolvedAddress}" : $resolvedAddress;
    }

    public function resolvedHttpMethod(?ProtocolConfigInterface $protocolConfig): string
    {
        $configuredMethod = is_string($this->httpMethod) ? trim($this->httpMethod) : '';

        if ($configuredMethod !== '') {
            return strtoupper($configuredMethod);
        }

        if ($protocolConfig instanceof HttpProtocolConfig) {
            return strtoupper($protocolConfig->method);
        }

        return '';
    }

    /**
     * Active parameters ordered by sequence.
     *
     * @return array<int, ParameterDefinitionData>
     */
    public function activeParameters(): array
    {
        $active = array_filter($this->parameters, fn (ParameterDefinitionData $p): bool => $p->isActive);

        usort($active, fn (ParameterDefinitionData $a, ParameterDefinitionData $b): int => $a->sequence <=> $b->sequence);

        return $active;
    }

    /**
     * Build a JSON payload template from this channel's active subscribe
     * (command) parameters, omitting optional button parameters by default.
     *
     * @return array<string, mixed>
     */
    public function buildCommandPayloadTemplate(): array
    {
        $payload = [];

        foreach ($this->activeParameters() as $parameter) {
            if ($parameter->resolvedWidgetType() === ControlWidgetType::Button && ! $parameter->required) {
                continue;
            }

            $payload = $parameter->placeValue($payload, $parameter->resolvedDefaultValue());
        }

        return $payload;
    }

    /**
     * Build an example payload template for publish channels (Device → Platform).
     *
     * @return array<string, mixed>
     */
    public function buildPublishPayloadTemplate(): array
    {
        $payload = [];

        foreach ($this->activeParameters() as $parameter) {
            $payload = $parameter->placeValue($payload, $parameter->resolvedDefaultValue());
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'label' => $this->label,
            'direction' => $this->direction->value,
            'purpose' => $this->purpose?->value,
            'transport' => $this->transport->value,
            'address' => $this->address,
            'http_method' => $this->httpMethod,
            'qos' => $this->qos,
            'retain' => $this->retain,
            'sequence' => $this->sequence,
            'options' => $this->options,
            'parameters' => array_map(
                fn (ParameterDefinitionData $p): array => [
                    'key' => $p->key,
                    'json_path' => $p->jsonPath,
                    'type' => $p->type->value,
                    'default_value' => $p->defaultValue,
                ],
                $this->parameters,
            ),
        ];
    }
}
