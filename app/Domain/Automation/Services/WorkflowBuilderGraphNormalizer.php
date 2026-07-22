<?php

declare(strict_types=1);

namespace App\Domain\Automation\Services;

use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use Illuminate\Support\Arr;

final class WorkflowBuilderGraphNormalizer
{
    /**
     * @param  array<string, mixed>  $graph
     * @return array<string, mixed>
     */
    public function normalize(array $graph): array
    {
        $nodes = $graph['nodes'] ?? null;
        if (! is_array($nodes)) {
            return $graph;
        }

        $graph['nodes'] = array_map(
            fn (mixed $node): mixed => is_array($node) ? $this->normalizeNode($node) : $node,
            $nodes,
        );

        return $graph;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function normalizeNode(array $node): array
    {
        $nodeType = $node['type'] ?? null;
        $config = Arr::get($node, 'data.config');

        if (! is_string($nodeType) || ! is_array($config)) {
            return $node;
        }

        if ($nodeType === 'telemetry-trigger') {
            $source = $config['source'] ?? null;

            if (is_array($source)) {
                $config['source'] = $this->normalizeSource($source);
            }
        }

        if ($nodeType === 'query') {
            $sources = $config['sources'] ?? null;

            if (is_array($sources)) {
                $config['sources'] = array_map(
                    fn (mixed $source): mixed => is_array($source) ? $this->normalizeSource($source) : $source,
                    $sources,
                );
            }
        }

        if ($nodeType === 'command') {
            $target = $config['target'] ?? null;

            if (is_array($target)) {
                $channelId = $this->resolvePositiveInt($target['device_channel_id'] ?? $target['topic_id'] ?? null);

                if ($channelId !== null) {
                    $target['device_channel_id'] = $channelId;
                }

                $config['target'] = $target;
            }
        }

        Arr::set($node, 'data.config', $config);

        return $node;
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function normalizeSource(array $source): array
    {
        $channelId = $this->resolvePositiveInt($source['device_channel_id'] ?? $source['topic_id'] ?? null);

        if ($channelId !== null) {
            $source['device_channel_id'] = $channelId;
        }

        $parameterKey = $this->resolveNonEmptyString($source['parameter_key'] ?? null);

        if ($parameterKey === null) {
            $parameterDefinitionId = $this->resolvePositiveInt($source['parameter_definition_id'] ?? null);

            if ($parameterDefinitionId !== null) {
                $parameterQuery = ProfileParameterDefinition::query()->whereKey($parameterDefinitionId);

                if ($channelId !== null) {
                    $parameterQuery->where('device_channel_id', $channelId);
                }

                $parameter = $parameterQuery->first(['key']);

                if ($parameter instanceof ProfileParameterDefinition) {
                    $source['parameter_key'] = $parameter->key;
                }
            }
        }

        return $source;
    }

    private function resolvePositiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value) || ! ctype_digit($value)) {
            return null;
        }

        $resolved = (int) $value;

        return $resolved > 0 ? $resolved : null;
    }

    private function resolveNonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $resolved = trim($value);

        return $resolved !== '' ? $resolved : null;
    }
}
