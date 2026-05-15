<?php

declare(strict_types=1);

namespace App\Domain\DataIngestion\Services;

use App\Domain\DeviceSchema\Models\DerivedParameterDefinition;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Domain\DeviceSchema\Models\ParameterDefinition;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class TelemetrySchemaMetadataCache
{
    private const SHARED_VERSION_CACHE_KEY = 'ingestion:telemetry-schema-metadata-version';

    /**
     * @var array<int, Collection<int, ParameterDefinition>>
     */
    private array $activeParametersByTopicId = [];

    /**
     * @var array<int, Collection<int, DerivedParameterDefinition>>
     */
    private array $derivedParametersBySchemaVersionId = [];

    /**
     * @var array<string, Carbon>
     */
    private array $refreshedAt = [];

    private ?string $observedSharedVersion = null;

    /**
     * @return Collection<int, ParameterDefinition>
     */
    public function activeParametersFor(SchemaVersionTopic $topic): Collection
    {
        $this->syncWithSharedVersion();

        $topicId = $this->resolveModelKeyAsInt($topic->getKey());
        $cacheKey = "topic:{$topicId}:parameters";

        if (! $this->shouldRefresh($cacheKey)) {
            return $this->activeParametersByTopicId[$topicId];
        }

        $this->activeParametersByTopicId[$topicId] = $topic->parameters()
            ->where('is_active', true)
            ->orderBy('sequence')
            ->get();

        $this->refreshedAt[$cacheKey] = now();

        return $this->activeParametersByTopicId[$topicId];
    }

    /**
     * @return Collection<int, DerivedParameterDefinition>
     */
    public function derivedParametersFor(DeviceSchemaVersion $schemaVersion): Collection
    {
        $this->syncWithSharedVersion();

        $schemaVersionId = $this->resolveModelKeyAsInt($schemaVersion->getKey());
        $cacheKey = "schema_version:{$schemaVersionId}:derived_parameters";

        if (! $this->shouldRefresh($cacheKey)) {
            return $this->derivedParametersBySchemaVersionId[$schemaVersionId];
        }

        $this->derivedParametersBySchemaVersionId[$schemaVersionId] = $schemaVersion->derivedParameters()
            ->orderBy('id')
            ->get();
        $this->refreshedAt[$cacheKey] = now();

        return $this->derivedParametersBySchemaVersionId[$schemaVersionId];
    }

    public static function invalidateSharedVersion(): void
    {
        self::sharedCacheStore()->forever(self::SHARED_VERSION_CACHE_KEY, (string) Str::uuid());
    }

    private function shouldRefresh(string $cacheKey): bool
    {
        $lastRefreshAt = $this->refreshedAt[$cacheKey] ?? null;

        if (! $lastRefreshAt instanceof Carbon) {
            return true;
        }

        $ttl = config('ingestion.metadata_ttl_seconds', 300);
        $ttlSeconds = is_numeric($ttl) ? max(1, (int) $ttl) : 300;

        return $lastRefreshAt->diffInSeconds(now()) > $ttlSeconds;
    }

    private function resolveModelKeyAsInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function syncWithSharedVersion(): void
    {
        $sharedVersion = self::sharedVersion();

        if ($this->observedSharedVersion === $sharedVersion) {
            return;
        }

        $this->observedSharedVersion = $sharedVersion;
        $this->flushLocalCaches();
    }

    private function flushLocalCaches(): void
    {
        $this->activeParametersByTopicId = [];
        $this->derivedParametersBySchemaVersionId = [];
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
