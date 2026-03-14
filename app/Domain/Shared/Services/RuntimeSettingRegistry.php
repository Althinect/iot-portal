<?php

declare(strict_types=1);

namespace App\Domain\Shared\Services;

use InvalidArgumentException;

class RuntimeSettingRegistry
{
    public const SOURCE_DEFAULT = 'default';

    public const SOURCE_GLOBAL = 'global';

    public const SOURCE_ORGANIZATION = 'organization';

    /**
     * @return array<string, array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     type: 'boolean'|'select'|'integer',
     *     config_key: string,
     *     options?: array<string, string>,
     *     min?: int,
     *     max?: int,
     *     supports_organization_overrides: bool
     * }>
     */
    public function definitions(): array
    {
        return [
            'ingestion.pipeline.enabled' => [
                'key' => 'ingestion.pipeline.enabled',
                'label' => 'Ingestion Pipeline Enabled',
                'description' => 'Enable or disable telemetry ingestion for matching devices.',
                'type' => 'boolean',
                'config_key' => 'ingestion.enabled',
                'supports_organization_overrides' => true,
            ],
            'ingestion.pipeline.driver' => [
                'key' => 'ingestion.pipeline.driver',
                'label' => 'Ingestion Driver',
                'description' => 'Select the ingestion runtime driver. Laravel is the only supported driver currently.',
                'type' => 'select',
                'config_key' => 'ingestion.driver',
                'options' => [
                    'laravel' => 'Laravel Queue',
                ],
                'supports_organization_overrides' => true,
            ],
            'ingestion.pipeline.broadcast_realtime' => [
                'key' => 'ingestion.pipeline.broadcast_realtime',
                'label' => 'Dashboard Realtime Broadcasts',
                'description' => 'Broadcast persisted telemetry updates to realtime dashboard streams.',
                'type' => 'boolean',
                'config_key' => 'ingestion.broadcast_realtime',
                'supports_organization_overrides' => true,
            ],
            'ingestion.pipeline.publish_analytics' => [
                'key' => 'ingestion.pipeline.publish_analytics',
                'label' => 'Analytics Publish Fan-Out',
                'description' => 'Publish post-ingestion analytics side effects for telemetry events.',
                'type' => 'boolean',
                'config_key' => 'ingestion.publish_analytics',
                'supports_organization_overrides' => true,
            ],
            'automation.pipeline.telemetry_fanout' => [
                'key' => 'automation.pipeline.telemetry_fanout',
                'label' => 'Automation Telemetry Fan-Out',
                'description' => 'Start automation matching and run queueing from telemetry events.',
                'type' => 'boolean',
                'config_key' => 'automation.telemetry_fanout_enabled',
                'supports_organization_overrides' => true,
            ],
            'iot.diagnostics.raw_telemetry_stream' => [
                'key' => 'iot.diagnostics.raw_telemetry_stream',
                'label' => 'Raw Telemetry Diagnostics Stream',
                'description' => 'Expose the pre-ingestion raw telemetry stream for diagnostics.',
                'type' => 'boolean',
                'config_key' => 'iot.broadcast.raw_telemetry',
                'supports_organization_overrides' => false,
            ],
            'horizon.auto_balancing.enabled' => [
                'key' => 'horizon.auto_balancing.enabled',
                'label' => 'Horizon Auto-Balancing',
                'description' => 'Allow Horizon to scale worker counts automatically between a minimum floor and queue-specific maximum caps. Requires Horizon restart.',
                'type' => 'boolean',
                'config_key' => 'horizon.auto_balancing.enabled',
                'supports_organization_overrides' => false,
            ],
            'horizon.default.max_processes' => [
                'key' => 'horizon.default.max_processes',
                'label' => 'Default Queue Max Workers',
                'description' => 'Maximum Horizon workers for the default queue when auto-balancing is enabled. Requires Horizon restart.',
                'type' => 'integer',
                'config_key' => 'horizon.auto_balancing.supervisors.default.max_processes',
                'min' => 1,
                'max' => 64,
                'supports_organization_overrides' => false,
            ],
            'horizon.ingestion.max_processes' => [
                'key' => 'horizon.ingestion.max_processes',
                'label' => 'Ingestion Queue Max Workers',
                'description' => 'Maximum Horizon workers for the ingestion queue when auto-balancing is enabled. Requires Horizon restart.',
                'type' => 'integer',
                'config_key' => 'horizon.auto_balancing.supervisors.ingestion.max_processes',
                'min' => 1,
                'max' => 64,
                'supports_organization_overrides' => false,
            ],
            'horizon.side_effects.max_processes' => [
                'key' => 'horizon.side_effects.max_processes',
                'label' => 'Side Effects Queue Max Workers',
                'description' => 'Maximum Horizon workers for the telemetry side-effects queue when auto-balancing is enabled. Requires Horizon restart.',
                'type' => 'integer',
                'config_key' => 'horizon.auto_balancing.supervisors.side_effects.max_processes',
                'min' => 1,
                'max' => 64,
                'supports_organization_overrides' => false,
            ],
            'horizon.automation.max_processes' => [
                'key' => 'horizon.automation.max_processes',
                'label' => 'Automation Queue Max Workers',
                'description' => 'Maximum Horizon workers for the automation queue when auto-balancing is enabled. Requires Horizon restart.',
                'type' => 'integer',
                'config_key' => 'horizon.auto_balancing.supervisors.automation.max_processes',
                'min' => 1,
                'max' => 64,
                'supports_organization_overrides' => false,
            ],
            'horizon.simulations.max_processes' => [
                'key' => 'horizon.simulations.max_processes',
                'label' => 'Simulations Queue Max Workers',
                'description' => 'Maximum Horizon workers for the simulations queue when auto-balancing is enabled. Requires Horizon restart.',
                'type' => 'integer',
                'config_key' => 'horizon.auto_balancing.supervisors.simulations.max_processes',
                'min' => 1,
                'max' => 64,
                'supports_organization_overrides' => false,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->definitions());
    }

    /**
     * @return list<string>
     */
    public function organizationScopedKeys(): array
    {
        return array_values(array_filter(
            $this->keys(),
            fn (string $key): bool => $this->supportsOrganizationOverrides($key),
        ));
    }

    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     type: 'boolean'|'select'|'integer',
     *     config_key: string,
     *     options?: array<string, string>,
     *     min?: int,
     *     max?: int,
     *     supports_organization_overrides: bool
     * }
     */
    public function definition(string $key): array
    {
        $definition = $this->definitions()[$key] ?? null;

        if (! is_array($definition)) {
            throw new InvalidArgumentException("Unsupported runtime setting [{$key}].");
        }

        return $definition;
    }

    public function defaultValue(string $key): mixed
    {
        $default = config($this->definition($key)['config_key']);
        $definition = $this->definition($key);

        return match ($definition['type']) {
            'boolean' => (bool) $default,
            'select' => $this->normalizeSelectValue($key, $default),
            'integer' => $this->normalizeIntegerValue($key, $default),
        };
    }

    public function supportsOrganizationOverrides(string $key): bool
    {
        return $this->definition($key)['supports_organization_overrides'];
    }

    /**
     * @return array<string, string>
     */
    public function options(string $key): array
    {
        return $this->definition($key)['options'] ?? [];
    }

    public function coerce(string $key, mixed $value): mixed
    {
        return match ($this->definition($key)['type']) {
            'boolean' => $this->normalizeBooleanValue($value),
            'select' => $this->normalizeSelectValue($key, $value),
            'integer' => $this->normalizeIntegerValue($key, $value),
        };
    }

    public function formatValue(string $key, mixed $value): string
    {
        return match ($this->definition($key)['type']) {
            'boolean' => (bool) $this->coerce($key, $value) ? 'Enabled' : 'Disabled',
            'select' => $this->formatSelectValue($key, $value),
            'integer' => number_format($this->normalizeIntegerValue($key, $value)),
        };
    }

    public function minimumValue(string $key): ?int
    {
        $minimum = $this->definition($key)['min'] ?? null;

        return is_int($minimum) ? $minimum : null;
    }

    public function maximumValue(string $key): ?int
    {
        $maximum = $this->definition($key)['max'] ?? null;

        return is_int($maximum) ? $maximum : null;
    }

    public function formatSource(string $source): string
    {
        return match ($source) {
            self::SOURCE_ORGANIZATION => 'Organization Override',
            self::SOURCE_GLOBAL => 'Global Override',
            default => 'Config Default',
        };
    }

    private function normalizeBooleanValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (bool) $value;
        }

        if (is_string($value)) {
            $normalized = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($normalized !== null) {
                return $normalized;
            }
        }

        return (bool) $value;
    }

    private function normalizeIntegerValue(string $key, mixed $value): int
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException('Unsupported runtime setting value ['.get_debug_type($value)."] for [{$key}].");
        }

        $resolvedValue = (int) $value;
        $minimum = $this->minimumValue($key) ?? PHP_INT_MIN;
        $maximum = $this->maximumValue($key) ?? PHP_INT_MAX;

        if ($resolvedValue < $minimum || $resolvedValue > $maximum) {
            throw new InvalidArgumentException("Runtime setting value [{$resolvedValue}] for [{$key}] must be between {$minimum} and {$maximum}.");
        }

        return $resolvedValue;
    }

    private function normalizeSelectValue(string $key, mixed $value): string
    {
        $resolvedValue = is_string($value)
            ? trim($value)
            : (is_scalar($value) ? (string) $value : '');

        if ($resolvedValue === '') {
            $resolvedValue = array_key_first($this->options($key)) ?? '';
        }

        if (! array_key_exists($resolvedValue, $this->options($key))) {
            throw new InvalidArgumentException("Unsupported runtime setting value [{$resolvedValue}] for [{$key}].");
        }

        return $resolvedValue;
    }

    private function formatSelectValue(string $key, mixed $value): string
    {
        $normalizedValue = $this->normalizeSelectValue($key, $value);

        return $this->options($key)[$normalizedValue] ?? $normalizedValue;
    }
}
