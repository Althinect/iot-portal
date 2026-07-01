<?php

declare(strict_types=1);

namespace App\Domain\DeviceProfile\DTO;

use App\Domain\DeviceManagement\ValueObjects\Protocol\ProtocolConfigInterface;
use App\Domain\DeviceProfile\Enums\Protocol;
use Illuminate\Support\Collection;

/**
 * The fully resolved, immutable runtime contract for a device profile version.
 *
 * This is the single object the ingestion pipeline loads: protocol config,
 * channels (each with scoped parameters), derived parameters, firmware
 * template, ingestion config and virtual standard profile. Callers never need
 * to traverse a DeviceProfile → DeviceProfileVersion chain.
 */
final readonly class DeviceProfileContract
{
    /**
     * @param  array<int, ChannelDefinition>  $channels
     * @param  array<int, DerivedParameterDefinitionData>  $derivedParameters
     * @param  array<string, mixed>|null  $ingestionConfig
     * @param  array<string, mixed>|null  $virtualStandardProfile
     */
    public function __construct(
        public int $versionId,
        public int $profileId,
        public string $profileKey,
        public string $profileName,
        public int $version,
        public string $status,
        public Protocol $protocol,
        public ?ProtocolConfigInterface $protocolConfig,
        public array $channels,
        public array $derivedParameters,
        public ?string $firmwareTemplate,
        public ?array $ingestionConfig,
        public ?array $virtualStandardProfile,
    ) {}

    /**
     * @return Collection<int, ChannelDefinition>
     */
    public function channels(): Collection
    {
        return collect($this->channels);
    }

    public function channelByKey(string $key): ?ChannelDefinition
    {
        foreach ($this->channels as $channel) {
            if ($channel->key === $key) {
                return $channel;
            }
        }

        return null;
    }

    public function channelById(int $id): ?ChannelDefinition
    {
        foreach ($this->channels as $channel) {
            if ($channel->id === $id) {
                return $channel;
            }
        }

        return null;
    }

    /**
     * @return Collection<int, ChannelDefinition>
     */
    public function publishChannels(): Collection
    {
        return $this->channels()->filter(fn (ChannelDefinition $c): bool => $c->isPublish());
    }

    /**
     * @return Collection<int, ChannelDefinition>
     */
    public function commandChannels(): Collection
    {
        return $this->channels()->filter(fn (ChannelDefinition $c): bool => $c->isPurposeCommand());
    }

    /**
     * @return Collection<int, DerivedParameterDefinitionData>
     */
    public function derivedParameters(): Collection
    {
        return collect($this->derivedParameters);
    }
}
