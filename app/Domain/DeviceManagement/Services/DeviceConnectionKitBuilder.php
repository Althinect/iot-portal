<?php

declare(strict_types=1);

namespace App\Domain\DeviceManagement\Services;

use App\Domain\DeviceManagement\Enums\MqttSecurityMode;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\ValueObjects\Protocol\MqttProtocolConfig;
use App\Domain\DeviceProfile\DTO\ChannelDefinition;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Services\DeviceProfileContractResolver;

final readonly class DeviceConnectionKitBuilder
{
    public function __construct(private DeviceProfileContractResolver $contractResolver) {}

    /**
     * @return array{
     *     device: array{name: string, identifier: string, client_id: string},
     *     profile: array{name: string, version: int},
     *     mqtt: array{broker_host: string, broker_port: int, use_tls: bool, security_mode: string, x509_enabled: bool, channels: list<array{key: string, label: string, direction: string, purpose: string, address: string, qos: int, retain: bool}>}|null
     * }
     */
    public function build(Device $device): array
    {
        $device->loadMissing('profileVersion.profile');
        $profileVersion = $device->profileVersion;

        abort_unless($profileVersion instanceof DeviceProfileVersion, 422);

        $identifier = is_string($device->external_id) && trim($device->external_id) !== ''
            ? trim($device->external_id)
            : (string) $device->uuid;
        $protocolConfig = $profileVersion->protocol_config;

        return [
            'device' => [
                'name' => $device->name,
                'identifier' => $identifier,
                'client_id' => $identifier,
            ],
            'profile' => [
                'name' => $profileVersion->profile?->name ?? 'Profile',
                'version' => (int) $profileVersion->version,
            ],
            'mqtt' => $protocolConfig instanceof MqttProtocolConfig
                ? [
                    'broker_host' => $protocolConfig->brokerHost,
                    'broker_port' => $protocolConfig->brokerPort,
                    'use_tls' => $protocolConfig->useTls,
                    'security_mode' => $protocolConfig->securityMode->label(),
                    'x509_enabled' => $protocolConfig->securityMode === MqttSecurityMode::X509Mtls,
                    'channels' => $this->mqttChannels($profileVersion, $identifier),
                ]
                : null,
        ];
    }

    /**
     * @return list<array{key: string, label: string, direction: string, purpose: string, address: string, qos: int, retain: bool}>
     */
    private function mqttChannels(DeviceProfileVersion $profileVersion, string $identifier): array
    {
        $contract = $this->contractResolver->resolve($profileVersion);

        return $contract->channels()
            ->sortBy(fn (ChannelDefinition $channel): int => $channel->sequence)
            ->map(fn (ChannelDefinition $channel): array => [
                'key' => $channel->key,
                'label' => $channel->label,
                'direction' => $channel->direction->value,
                'purpose' => $channel->resolvedPurpose()->value,
                'address' => $channel->resolvedAddress($identifier, $contract->protocolConfig),
                'qos' => $channel->qos,
                'retain' => $channel->retain,
            ])
            ->values()
            ->all();
    }
}
