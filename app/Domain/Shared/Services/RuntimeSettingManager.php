<?php

declare(strict_types=1);

namespace App\Domain\Shared\Services;

use App\Domain\Shared\Models\Organization;
use Illuminate\Support\Facades\DB;
use JsonException;
use Laravel\Pennant\Feature;

class RuntimeSettingManager
{
    /**
     * @var array<string, array{
     *     effective_value: mixed,
     *     source: string,
     *     default_value: mixed,
     *     global_override_exists: bool,
     *     global_override_value: mixed,
     *     organization_override_exists: bool,
     *     organization_override_value: mixed
     * }>
     */
    private array $resolvedSettingCache = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $overrideCache = [];

    public function __construct(
        private readonly RuntimeSettingRegistry $registry,
    ) {}

    /**
     * @return array<string, array{
     *     effective_value: mixed,
     *     source: string,
     *     default_value: mixed,
     *     global_override_exists: bool,
     *     global_override_value: mixed,
     *     organization_override_exists: bool,
     *     organization_override_value: mixed
     * }>
     */
    public function resolvedSettings(Organization|int|string|null $organization = null): array
    {
        $resolved = [];

        foreach ($this->registry->keys() as $key) {
            $resolved[$key] = $this->resolvedSetting($key, $organization);
        }

        return $resolved;
    }

    /**
     * @return array{
     *     effective_value: mixed,
     *     source: string,
     *     default_value: mixed,
     *     global_override_exists: bool,
     *     global_override_value: mixed,
     *     organization_override_exists: bool,
     *     organization_override_value: mixed
     * }
     */
    public function resolvedSetting(string $key, Organization|int|string|null $organization = null): array
    {
        $cacheKey = $this->resolvedSettingCacheKey($key, $organization);

        if (array_key_exists($cacheKey, $this->resolvedSettingCache)) {
            return $this->resolvedSettingCache[$cacheKey];
        }

        $defaultValue = $this->registry->defaultValue($key);
        $globalOverride = $this->storedOverride($key);
        $organizationOverride = ($organization !== null && $this->registry->supportsOrganizationOverrides($key))
            ? $this->storedOverride($key, $organization)
            : ['exists' => false, 'value' => null];

        if ($organizationOverride['exists']) {
            return $this->resolvedSettingCache[$cacheKey] = [
                'effective_value' => $organizationOverride['value'],
                'source' => RuntimeSettingRegistry::SOURCE_ORGANIZATION,
                'default_value' => $defaultValue,
                'global_override_exists' => $globalOverride['exists'],
                'global_override_value' => $globalOverride['value'],
                'organization_override_exists' => true,
                'organization_override_value' => $organizationOverride['value'],
            ];
        }

        if ($globalOverride['exists']) {
            return $this->resolvedSettingCache[$cacheKey] = [
                'effective_value' => $globalOverride['value'],
                'source' => RuntimeSettingRegistry::SOURCE_GLOBAL,
                'default_value' => $defaultValue,
                'global_override_exists' => true,
                'global_override_value' => $globalOverride['value'],
                'organization_override_exists' => false,
                'organization_override_value' => null,
            ];
        }

        return $this->resolvedSettingCache[$cacheKey] = [
            'effective_value' => $defaultValue,
            'source' => RuntimeSettingRegistry::SOURCE_DEFAULT,
            'default_value' => $defaultValue,
            'global_override_exists' => false,
            'global_override_value' => null,
            'organization_override_exists' => false,
            'organization_override_value' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function effectiveValues(Organization|int|string|null $organization = null): array
    {
        $values = [];

        foreach ($this->resolvedSettings($organization) as $key => $resolved) {
            $values[$key] = $resolved['effective_value'];
        }

        return $values;
    }

    public function booleanValue(string $key, Organization|int|string|null $organization = null): bool
    {
        return (bool) $this->resolvedSetting($key, $organization)['effective_value'];
    }

    public function stringValue(string $key, Organization|int|string|null $organization = null): string
    {
        $value = $this->resolvedSetting($key, $organization)['effective_value'];
        $defaultValue = $this->registry->defaultValue($key);

        return is_string($value) && $value !== ''
            ? $value
            : (is_string($defaultValue) ? $defaultValue : '');
    }

    public function intValue(string $key, Organization|int|string|null $organization = null): int
    {
        $value = $this->resolvedSetting($key, $organization)['effective_value'];
        $defaultValue = $this->registry->defaultValue($key);

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return is_int($defaultValue) ? $defaultValue : 0;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function setGlobalOverrides(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->assertManagedKey($key);

            Feature::for(null)->activate($this->overrideFeatureName($key), $this->registry->coerce($key, $value));
        }

        $this->purgeManagedFeatureCache();
    }

    /**
     * @param  list<string>  $keys
     */
    public function resetGlobalOverrides(array $keys): void
    {
        foreach ($keys as $key) {
            $this->assertManagedKey($key);

            Feature::for(null)->forget($this->overrideFeatureName($key));
        }

        $this->purgeManagedFeatureCache();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function setOrganizationOverrides(Organization $organization, array $values): void
    {
        foreach ($values as $key => $value) {
            $this->assertManagedKey($key);

            if (! $this->registry->supportsOrganizationOverrides($key)) {
                continue;
            }

            Feature::for($organization)->activate($this->overrideFeatureName($key), $this->registry->coerce($key, $value));
        }

        $this->purgeManagedFeatureCache();
    }

    /**
     * @param  list<string>  $keys
     */
    public function resetOrganizationOverrides(Organization $organization, array $keys): void
    {
        foreach ($keys as $key) {
            $this->assertManagedKey($key);

            if (! $this->registry->supportsOrganizationOverrides($key)) {
                continue;
            }

            Feature::for($organization)->forget($this->overrideFeatureName($key));
        }

        $this->purgeManagedFeatureCache();
    }

    /**
     * @return array{exists: bool, value: mixed}
     */
    private function storedOverride(string $key, Organization|int|string|null $organization = null): array
    {
        $scope = $this->serializeScope($organization);

        if ($scope === null) {
            return [
                'exists' => false,
                'value' => null,
            ];
        }

        $scopeOverrides = $this->scopeOverrides($scope);
        $overrideKey = $this->overrideFeatureName($key);

        if (! array_key_exists($overrideKey, $scopeOverrides)) {
            return [
                'exists' => false,
                'value' => null,
            ];
        }

        return [
            'exists' => true,
            'value' => $this->registry->coerce($key, $scopeOverrides[$overrideKey]),
        ];
    }

    private function purgeManagedFeatureCache(): void
    {
        Feature::purge($this->registry->keys());
        Feature::flushCache();
        $this->overrideCache = [];
        $this->resolvedSettingCache = [];
    }

    private function overrideFeatureName(string $key): string
    {
        return "runtime-settings.override.{$key}";
    }

    private function serializeScope(Organization|int|string|null $organization = null): ?string
    {
        if ($organization === null) {
            return Feature::serializeScope(null);
        }

        if ($organization instanceof Organization) {
            return Feature::serializeScope($organization);
        }

        $organizationId = $this->normalizeOrganizationId($organization);

        if ($organizationId !== null) {
            return Organization::class.'|'.$organizationId;
        }

        return null;
    }

    private function normalizeOrganizationId(int|string $organization): ?int
    {
        if (is_int($organization)) {
            return $organization > 0 ? $organization : null;
        }

        if (! ctype_digit($organization)) {
            return null;
        }

        $organizationId = (int) $organization;

        return $organizationId > 0 ? $organizationId : null;
    }

    private function tableName(): string
    {
        $configuredDefaultStore = config('pennant.default', 'database');
        $defaultStore = is_string($configuredDefaultStore) && $configuredDefaultStore !== ''
            ? $configuredDefaultStore
            : 'database';

        $configuredTable = config("pennant.stores.{$defaultStore}.table", 'features');

        return is_string($configuredTable) && $configuredTable !== ''
            ? $configuredTable
            : 'features';
    }

    private function assertManagedKey(string $key): void
    {
        $this->registry->definition($key);
    }

    /**
     * @return array<string, mixed>
     */
    private function scopeOverrides(string $scope): array
    {
        if (array_key_exists($scope, $this->overrideCache)) {
            return $this->overrideCache[$scope];
        }

        $rows = DB::table($this->tableName())
            ->whereIn('name', array_map(
                fn (string $key): string => $this->overrideFeatureName($key),
                $this->registry->keys(),
            ))
            ->where('scope', $scope)
            ->get(['name', 'value']);

        $this->overrideCache[$scope] = $rows
            ->mapWithKeys(function (object $row): array {
                try {
                    $decoded = json_decode((string) $row->value, true, flags: JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    $decoded = null;
                }

                return [
                    (string) $row->name => $decoded,
                ];
            })
            ->all();

        return $this->overrideCache[$scope];
    }

    private function resolvedSettingCacheKey(string $key, Organization|int|string|null $organization = null): string
    {
        return $key.'|'.($this->serializeScope($organization) ?? 'missing');
    }
}
