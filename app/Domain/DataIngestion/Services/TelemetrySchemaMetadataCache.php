<?php

declare(strict_types=1);

namespace App\Domain\DataIngestion\Services;

use App\Domain\DeviceSchema\Models\DerivedParameterDefinition;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Domain\DeviceSchema\Models\ParameterDefinition;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class TelemetrySchemaMetadataCache
{
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

    /**
     * @return Collection<int, ParameterDefinition>
     */
    public function activeParametersFor(SchemaVersionTopic $topic): Collection
    {
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
        $schemaVersionId = $this->resolveModelKeyAsInt($schemaVersion->getKey());
        $cacheKey = "schema_version:{$schemaVersionId}:derived_parameters";

        if (! $this->shouldRefresh($cacheKey)) {
            return $this->derivedParametersBySchemaVersionId[$schemaVersionId];
        }

        $this->derivedParametersBySchemaVersionId[$schemaVersionId] = $schemaVersion->derivedParameters()->get();
        $this->refreshedAt[$cacheKey] = now();

        return $this->derivedParametersBySchemaVersionId[$schemaVersionId];
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
}
