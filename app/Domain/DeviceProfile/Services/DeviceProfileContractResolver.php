<?php

declare(strict_types=1);

namespace App\Domain\DeviceProfile\Services;

use App\Domain\DeviceProfile\DTO\ChannelDefinition;
use App\Domain\DeviceProfile\DTO\DerivedParameterDefinitionData;
use App\Domain\DeviceProfile\DTO\DeviceProfileContract;
use App\Domain\DeviceProfile\DTO\ParameterDefinitionData;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\DeviceProfile;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Models\ProfileDerivedParameterDefinition;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class DeviceProfileContractResolver
{
    private const SHARED_VERSION_CACHE_KEY = 'device-profile:contract-shared-version';

    /** @var array<int, DeviceProfileContract> */
    private array $contractsByVersionId = [];

    /** @var array<string, Carbon> */
    private array $refreshedAt = [];

    private ?string $observedSharedVersion = null;

    public function resolve(DeviceProfileVersion $version): DeviceProfileContract
    {
        $this->syncWithSharedVersion();

        $versionId = $version->id;
        $cacheKey = "version:{$versionId}";

        if (! $this->shouldRefresh($cacheKey)) {
            return $this->contractsByVersionId[$versionId];
        }

        $this->contractsByVersionId[$versionId] = $this->buildContract($version);
        $this->refreshedAt[$cacheKey] = now();

        return $this->contractsByVersionId[$versionId];
    }

    public function resolveById(int $versionId): ?DeviceProfileContract
    {
        $this->syncWithSharedVersion();

        if (isset($this->contractsByVersionId[$versionId])) {
            return $this->contractsByVersionId[$versionId];
        }

        $version = DeviceProfileVersion::query()->find($versionId);

        if (! $version instanceof DeviceProfileVersion) {
            return null;
        }

        return $this->resolve($version);
    }

    public static function invalidateSharedVersion(): void
    {
        self::sharedCacheStore()->forever(self::SHARED_VERSION_CACHE_KEY, (string) Str::uuid());
    }

    private function buildContract(DeviceProfileVersion $version): DeviceProfileContract
    {
        $version->load([
            'profile',
            'channels' => fn ($query) => $query->orderBy('sequence'),
            'channels.parameters' => fn ($query) => $query->orderBy('sequence'),
            'derivedParameters',
        ]);

        $profile = $version->profile;

        if (! $profile instanceof DeviceProfile) {
            throw new \RuntimeException('Device profile version is missing its parent profile.');
        }

        $channels = $version->channels
            ->map(fn (DeviceChannel $channel): ChannelDefinition => $this->mapChannel($channel))
            ->all();

        $derivedParameters = $version->derivedParameters
            ->map(fn (ProfileDerivedParameterDefinition $derived): DerivedParameterDefinitionData => $this->mapDerivedParameter($derived))
            ->all();

        return new DeviceProfileContract(
            versionId: $version->id,
            profileId: $profile->id,
            profileKey: $profile->key,
            profileName: $profile->name,
            version: $version->version,
            status: $version->status,
            protocol: $version->protocol,
            protocolConfig: $version->protocol_config,
            channels: array_values($channels),
            derivedParameters: array_values($derivedParameters),
            firmwareTemplate: $version->firmware_template,
            ingestionConfig: $version->ingestion_config,
            virtualStandardProfile: $version->virtual_standard_profile,
        );
    }

    private function mapChannel(DeviceChannel $channel): ChannelDefinition
    {
        $parameters = $channel->parameters
            ->map(fn (ProfileParameterDefinition $parameter): ParameterDefinitionData => $this->mapParameter($parameter))
            ->all();

        return new ChannelDefinition(
            id: $channel->id,
            key: $channel->key,
            label: $channel->label,
            direction: $channel->direction,
            purpose: $channel->purpose,
            transport: $channel->transport,
            address: $channel->address,
            httpMethod: $channel->http_method,
            description: $channel->description,
            qos: $channel->qos,
            retain: $channel->retain,
            sequence: $channel->sequence,
            options: $channel->options,
            parameters: array_values($parameters),
        );
    }

    private function mapParameter(ProfileParameterDefinition $parameter): ParameterDefinitionData
    {
        return new ParameterDefinitionData(
            id: $parameter->id,
            key: $parameter->key,
            label: $parameter->label,
            jsonPath: $parameter->json_path,
            type: $parameter->type,
            category: $parameter->category,
            unit: $parameter->unit,
            required: $parameter->required,
            isCritical: $parameter->is_critical,
            isActive: $parameter->is_active,
            sequence: $parameter->sequence,
            validationErrorCode: $parameter->validation_error_code,
            validationRules: $parameter->validation_rules,
            controlUi: $parameter->control_ui,
            mutationExpression: $parameter->mutation_expression,
            defaultValue: $parameter->default_value,
        );
    }

    private function mapDerivedParameter(ProfileDerivedParameterDefinition $derived): DerivedParameterDefinitionData
    {
        return new DerivedParameterDefinitionData(
            id: $derived->id,
            key: $derived->key,
            label: $derived->label,
            dataType: $derived->data_type,
            unit: $derived->unit,
            expression: $derived->expression,
            dependencies: $derived->dependencies,
            jsonPath: $derived->json_path,
        );
    }

    private function shouldRefresh(string $cacheKey): bool
    {
        $lastRefreshAt = $this->refreshedAt[$cacheKey] ?? null;

        if (! $lastRefreshAt instanceof Carbon) {
            return true;
        }

        $ttl = config('device-profile.contract_ttl_seconds', 300);
        $ttlSeconds = is_numeric($ttl) ? max(1, (int) $ttl) : 300;

        return $lastRefreshAt->diffInSeconds(now()) > $ttlSeconds;
    }

    private function syncWithSharedVersion(): void
    {
        $sharedVersion = self::sharedVersion();

        if ($this->observedSharedVersion === $sharedVersion) {
            return;
        }

        $this->observedSharedVersion = $sharedVersion;
        $this->contractsByVersionId = [];
        $this->refreshedAt = [];
    }

    private static function sharedVersion(): string
    {
        $sharedVersion = self::sharedCacheStore()->get(self::SHARED_VERSION_CACHE_KEY);

        if (is_string($sharedVersion) && trim($sharedVersion) !== '') {
            return $sharedVersion;
        }

        $generatedVersion = (string) Str::uuid();
        self::sharedCacheStore()->forever(self::SHARED_VERSION_CACHE_KEY, $generatedVersion);

        return $generatedVersion;
    }

    private static function sharedCacheStore(): Repository
    {
        return Cache::store(self::sharedCacheStoreName());
    }

    private static function sharedCacheStoreName(): string
    {
        $defaultStore = config('cache.default');

        if (is_string($defaultStore) && $defaultStore !== '' && $defaultStore !== 'array') {
            return $defaultStore;
        }

        return 'file';
    }
}
