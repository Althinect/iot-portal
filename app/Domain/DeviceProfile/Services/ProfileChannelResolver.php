<?php

declare(strict_types=1);

namespace App\Domain\DeviceProfile\Services;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\DTO\ChannelDefinition;
use App\Domain\DeviceProfile\DTO\DeviceProfileContract;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use Illuminate\Support\Carbon;

/**
 * Resolves an inbound transport address (MQTT topic or HTTP path) to the
 * owning device, channel and resolved profile contract in a single lookup,
 * without traversing the legacy profile hierarchy.
 */
class ProfileChannelResolver
{
    /**
     * @var array<string, array{device: Device, channel: ChannelDefinition, contract: DeviceProfileContract}>
     */
    private array $registry = [];

    private ?Carbon $lastRegistryRefreshAt = null;

    public function __construct(
        private readonly DeviceProfileContractResolver $contractResolver,
    ) {}

    /**
     * @return array{device: Device, channel: ChannelDefinition, contract: DeviceProfileContract}|null
     */
    public function resolve(string $address, ?string $method = null): ?array
    {
        if ($this->shouldRefreshRegistry()) {
            $this->refreshRegistry();
        }

        $lookupKey = $this->lookupKey($address, $method);

        return $this->registry[$lookupKey] ?? null;
    }

    public function refreshRegistry(): void
    {
        $this->registry = [];

        $devices = Device::query()
            ->with(['profileVersion'])
            ->whereNotNull('device_profile_version_id')
            ->get();

        foreach ($devices as $device) {
            $version = $device->profileVersion;

            if (! $version instanceof DeviceProfileVersion) {
                continue;
            }

            $contract = $this->contractResolver->resolve($version);
            $identifier = $this->deviceIdentifier($device);

            foreach ($contract->publishChannels() as $channel) {
                $resolvedAddress = $channel->resolvedAddress($identifier, $contract->protocolConfig);

                if ($resolvedAddress === '') {
                    continue;
                }

                $entry = [
                    'device' => $device,
                    'channel' => $channel,
                    'contract' => $contract,
                ];

                $this->registry[$resolvedAddress] ??= $entry;
                $this->registry[$channel->resolvedLookupKey($identifier, $contract->protocolConfig)] = $entry;
            }
        }

        $this->lastRegistryRefreshAt = now();
    }

    private function deviceIdentifier(Device $device): string
    {
        $externalId = $device->getAttribute('external_id');

        return is_string($externalId) && trim($externalId) !== '' ? $externalId : (string) $device->uuid;
    }

    private function shouldRefreshRegistry(): bool
    {
        if (! $this->lastRegistryRefreshAt instanceof Carbon) {
            return true;
        }

        $ttl = config('device-profile.channel_registry_ttl_seconds', 30);
        $ttlSeconds = is_numeric($ttl) ? (int) $ttl : 30;

        return $this->lastRegistryRefreshAt->diffInSeconds(now()) > $ttlSeconds;
    }

    private function lookupKey(string $address, ?string $method): string
    {
        $normalizedMethod = is_string($method) ? strtoupper(trim($method)) : '';

        return $normalizedMethod !== '' ? "{$normalizedMethod} {$address}" : $address;
    }
}
