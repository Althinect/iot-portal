<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\DataIngestion\Models\DeviceSignalBinding;
use App\Domain\DeviceManagement\Enums\MqttSecurityMode;
use App\Domain\DeviceManagement\Enums\ProtocolType;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Models\DeviceType;
use App\Domain\DeviceManagement\ValueObjects\Protocol\MqttProtocolConfig;
use App\Domain\DeviceSchema\Enums\ParameterCategory;
use App\Domain\DeviceSchema\Enums\ParameterDataType;
use App\Domain\DeviceSchema\Enums\TopicDirection;
use App\Domain\DeviceSchema\Enums\TopicPurpose;
use App\Domain\DeviceSchema\Models\DerivedParameterDefinition;
use App\Domain\DeviceSchema\Models\DeviceSchema;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Domain\DeviceSchema\Models\ParameterDefinition;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Domain\Shared\Models\Organization;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

abstract class LegacyImoniMigrationSeederSupport extends Seeder
{
    protected const HUB_DEVICE_TYPE_KEY = 'legacy_hub';

    protected const HUB_DEVICE_TYPE_NAME = 'Legacy Hub';

    protected const HUB_BASE_TOPIC = 'devices/legacy-hub';

    protected const HUB_SCHEMA_NAME = 'Legacy Hub Presence';

    abstract protected function organizationSlug(): string;

    abstract protected function organizationName(): string;

    /**
     * @return array<int, array{external_id: string, name: string, legacy_device_uid: string|null, legacy_virtual_device_id: string|null}>
     */
    abstract protected function hubInventory(): array;

    /**
     * @return array<string, array<string, array<int, string>>>
     */
    protected function specialDecodeProfiles(): array
    {
        return [];
    }

    protected function ensureOrganization(): Organization
    {
        /** @var Organization $organization */
        $organization = Organization::withTrashed()->updateOrCreate(
            ['slug' => $this->organizationSlug()],
            [
                'name' => $this->organizationName(),
                'deleted_at' => null,
            ],
        );

        return $organization;
    }

    protected function upsertHubSchemaVersion(): DeviceSchemaVersion
    {
        return $this->upsertSchemaVersion(
            deviceTypeKey: self::HUB_DEVICE_TYPE_KEY,
            deviceTypeName: self::HUB_DEVICE_TYPE_NAME,
            baseTopic: self::HUB_BASE_TOPIC,
            schemaName: self::HUB_SCHEMA_NAME,
            parameters: [
                [
                    'key' => 'source_id',
                    'label' => 'Legacy Source ID',
                    'json_path' => '$.source_id',
                    'type' => ParameterDataType::String,
                    'required' => false,
                    'sequence' => 1,
                ],
            ],
            topicKey: 'heartbeat',
            topicLabel: 'Heartbeat',
            topicSuffix: 'heartbeat',
            purpose: TopicPurpose::State,
            notes: $this->organizationName().' legacy iMoni hub presence contract.',
        );
    }

    /**
     * @return array<string, Device>
     */
    protected function ensureHubs(Organization $organization): array
    {
        $schemaVersion = $this->upsertHubSchemaVersion();
        $hubs = [];

        foreach ($this->hubInventory() as $hubConfig) {
            $hubs[$hubConfig['external_id']] = Device::withTrashed()->updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'external_id' => $hubConfig['external_id'],
                ],
                [
                    'device_type_id' => $schemaVersion->schema->device_type_id,
                    'device_schema_version_id' => $schemaVersion->id,
                    'parent_device_id' => null,
                    'name' => $hubConfig['name'],
                    'metadata' => [
                        'migration_origin' => $this->organizationSlug(),
                        'migration_role' => 'hub',
                        'migration_device_type' => 'IMoni Hub',
                        'source_adapter' => 'imoni',
                        'legacy_device_uid' => $hubConfig['legacy_device_uid'],
                        'legacy_virtual_device_id' => $hubConfig['legacy_virtual_device_id'],
                    ],
                    'is_active' => true,
                    'connection_state' => 'offline',
                    'last_seen_at' => null,
                    'deleted_at' => null,
                ],
            );
        }

        return $hubs;
    }

    protected function cleanupHubs(Organization $organization): void
    {
        $expectedExternalIds = array_column($this->hubInventory(), 'external_id');

        $this->cleanupDevices(
            organization: $organization,
            migrationDeviceType: 'IMoni Hub',
            expectedExternalIds: $expectedExternalIds,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $parameters
     * @param  array<int, array<string, mixed>>  $derivedParameters
     * @param  array<string, mixed>|null  $virtualStandardProfile
     */
    protected function upsertSchemaVersion(
        string $deviceTypeKey,
        string $deviceTypeName,
        string $baseTopic,
        string $schemaName,
        array $parameters,
        string $topicKey = 'telemetry',
        string $topicLabel = 'Telemetry',
        string $topicSuffix = 'telemetry',
        TopicPurpose $purpose = TopicPurpose::Telemetry,
        int $version = 1,
        string $status = 'active',
        string $notes = 'Legacy iMoni migration schema.',
        array $derivedParameters = [],
        ?array $virtualStandardProfile = null,
    ): DeviceSchemaVersion {
        $deviceType = DeviceType::query()->updateOrCreate(
            [
                'organization_id' => null,
                'key' => $deviceTypeKey,
            ],
            [
                'name' => $deviceTypeName,
                'default_protocol' => ProtocolType::Mqtt,
                'protocol_config' => (new MqttProtocolConfig(
                    brokerHost: 'nats',
                    brokerPort: 1883,
                    username: null,
                    password: null,
                    useTls: false,
                    baseTopic: $baseTopic,
                    securityMode: MqttSecurityMode::UsernamePassword,
                ))->toArray(),
                'virtual_standard_profile' => $virtualStandardProfile,
            ],
        );

        $schema = DeviceSchema::query()->firstOrCreate(
            [
                'device_type_id' => $deviceType->id,
                'name' => $schemaName,
            ],
        );

        $schemaVersion = DeviceSchemaVersion::query()->firstOrCreate(
            [
                'device_schema_id' => $schema->id,
                'version' => $version,
            ],
            [
                'status' => $status,
                'notes' => $notes,
            ],
        );

        $schemaVersion->fill([
            'status' => $status,
            'notes' => $notes,
        ])->save();

        $topic = SchemaVersionTopic::query()->updateOrCreate(
            [
                'device_schema_version_id' => $schemaVersion->id,
                'key' => $topicKey,
            ],
            [
                'label' => $topicLabel,
                'direction' => TopicDirection::Publish,
                'purpose' => $purpose,
                'suffix' => $topicSuffix,
                'qos' => 1,
                'retain' => false,
                'sequence' => 0,
            ],
        );

        foreach ($parameters as $parameter) {
            ParameterDefinition::query()->updateOrCreate(
                [
                    'schema_version_topic_id' => $topic->id,
                    'key' => $parameter['key'],
                ],
                [
                    'label' => $parameter['label'],
                    'json_path' => $parameter['json_path'],
                    'type' => $parameter['type'],
                    'unit' => $parameter['unit'] ?? null,
                    'required' => $parameter['required'] ?? false,
                    'is_critical' => $parameter['is_critical'] ?? false,
                    'category' => $parameter['category'] ?? ParameterCategory::Measurement,
                    'validation_rules' => $parameter['validation_rules'] ?? null,
                    'control_ui' => $parameter['control_ui'] ?? null,
                    'mutation_expression' => $parameter['mutation_expression'] ?? null,
                    'sequence' => $parameter['sequence'] ?? 0,
                    'is_active' => true,
                ],
            );
        }

        $parameterKeys = array_values(array_map(
            static fn (array $parameter): string => $parameter['key'],
            $parameters,
        ));

        ParameterDefinition::query()
            ->where('schema_version_topic_id', $topic->id)
            ->when(
                $parameterKeys !== [],
                fn ($query) => $query->whereNotIn('key', $parameterKeys),
            )
            ->delete();

        foreach ($derivedParameters as $derivedParameter) {
            DerivedParameterDefinition::query()->updateOrCreate(
                [
                    'device_schema_version_id' => $schemaVersion->id,
                    'key' => $derivedParameter['key'],
                ],
                [
                    'label' => $derivedParameter['label'],
                    'data_type' => $derivedParameter['data_type'],
                    'unit' => $derivedParameter['unit'] ?? null,
                    'expression' => $derivedParameter['expression'],
                    'dependencies' => $derivedParameter['dependencies'] ?? null,
                    'json_path' => $derivedParameter['json_path'] ?? null,
                ],
            );
        }

        $derivedKeys = array_values(array_map(
            static fn (array $parameter): string => $parameter['key'],
            $derivedParameters,
        ));

        $derivedParameterCleanupQuery = DerivedParameterDefinition::query()
            ->where('device_schema_version_id', $schemaVersion->id);

        if ($derivedKeys !== []) {
            $derivedParameterCleanupQuery->whereNotIn('key', $derivedKeys);
        }

        $derivedParameterCleanupQuery->delete();

        /** @var DeviceSchemaVersion $freshSchemaVersion */
        $freshSchemaVersion = $schemaVersion->fresh(['schema']);

        return $freshSchemaVersion;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    protected function upsertChildDevice(
        Organization $organization,
        Device $parentDevice,
        DeviceSchemaVersion $schemaVersion,
        string $externalId,
        string $name,
        array $metadata,
    ): Device {
        /** @var Device $device */
        $device = Device::withTrashed()->updateOrCreate(
            [
                'organization_id' => $organization->id,
                'external_id' => $externalId,
            ],
            [
                'device_type_id' => $schemaVersion->schema->device_type_id,
                'device_schema_version_id' => $schemaVersion->id,
                'parent_device_id' => $parentDevice->id,
                'name' => $name,
                'metadata' => $metadata,
                'is_active' => true,
                'connection_state' => 'offline',
                'last_seen_at' => null,
                'deleted_at' => null,
            ],
        );

        return $device;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    protected function upsertStandaloneDevice(
        Organization $organization,
        DeviceSchemaVersion $schemaVersion,
        string $externalId,
        string $name,
        array $metadata,
    ): Device {
        /** @var Device $device */
        $device = Device::withTrashed()->updateOrCreate(
            [
                'organization_id' => $organization->id,
                'external_id' => $externalId,
            ],
            [
                'device_type_id' => $schemaVersion->schema->device_type_id,
                'device_schema_version_id' => $schemaVersion->id,
                'parent_device_id' => null,
                'name' => $name,
                'metadata' => $metadata,
                'is_active' => true,
                'connection_state' => 'offline',
                'last_seen_at' => null,
                'deleted_at' => null,
            ],
        );

        return $device;
    }

    protected function sourceTopicFor(string $hubImei, string $peripheralTypeHex): string
    {
        return 'migration/source/imoni/'.$hubImei.'/'.strtoupper($peripheralTypeHex).'/telemetry';
    }

    protected function normalizedSourcePath(string $legacyPath): ?string
    {
        if ($legacyPath === '') {
            return null;
        }

        $pattern = '/^peripheralDataArr\.([^.]+)\.([^.]+)\.3$/';
        $matches = [];

        if (! preg_match($pattern, $legacyPath, $matches)) {
            return null;
        }

        $objectKey = $matches[2];

        return ctype_digit($objectKey)
            ? '$.io_'.$objectKey.'_value'
            : '$.object_values.'.$objectKey.'.value';
    }

    protected function mutationExpressionForParameter(array $deviceConfig, string $parameterKey): ?array
    {
        $conditionalCalibrations = $deviceConfig['conditional_calibrations'] ?? [];

        if (is_array($conditionalCalibrations)) {
            $expression = $conditionalCalibrations[$parameterKey] ?? null;

            if (is_string($expression) && trim($expression) !== '') {
                return $this->normalizeMutationExpressionVariables(
                    json_decode($expression, true, 512, JSON_THROW_ON_ERROR),
                );
            }
        }

        $calibrations = $deviceConfig['calibrations'] ?? [];

        if (! is_array($calibrations)) {
            return null;
        }

        $calibration = $calibrations[$parameterKey] ?? null;

        if (! is_string($calibration) || trim($calibration) === '') {
            return null;
        }

        $normalized = str_replace(' ', '', $calibration);
        $matches = [];

        if (preg_match('/^[A-Za-z0-9_]+\/([0-9.]+)$/', $normalized, $matches) === 1) {
            return [
                '/' => [
                    ['var' => 'val'],
                    (float) $matches[1],
                ],
            ];
        }

        if (preg_match('/^[A-Za-z0-9_]+\*([0-9.]+)$/', $normalized, $matches) === 1) {
            return [
                '*' => [
                    ['var' => 'val'],
                    (float) $matches[1],
                ],
            ];
        }

        return null;
    }

    protected function normalizeMutationExpressionVariables(mixed $expression): mixed
    {
        if (! is_array($expression)) {
            return $expression;
        }

        if (array_key_exists('var', $expression) && is_string($expression['var'])) {
            $expression['var'] = 'val';
        }

        foreach ($expression as $key => $value) {
            $expression[$key] = $this->normalizeMutationExpressionVariables($value);
        }

        return $expression;
    }

    /**
     * @param  array<string, ParameterDefinition>  $parametersByKey
     * @param  array<string, array<string, mixed>>  $bindingDefinitions
     */
    protected function syncBindings(
        Device $device,
        string $hubImei,
        string $peripheralTypeHex,
        array $parametersByKey,
        array $bindingDefinitions,
        array $deviceMetadata = [],
    ): void {
        $expectedParameterIds = [];

        foreach ($bindingDefinitions as $parameterKey => $bindingDefinition) {
            $parameter = $parametersByKey[$parameterKey] ?? null;

            if (! $parameter instanceof ParameterDefinition) {
                continue;
            }

            $sourceJsonPath = $bindingDefinition['source_json_path'] ?? null;

            if (! is_string($sourceJsonPath) || trim($sourceJsonPath) === '') {
                continue;
            }

            $expectedParameterIds[] = $parameter->id;

            DeviceSignalBinding::query()->updateOrCreate(
                [
                    'device_id' => $device->id,
                    'parameter_definition_id' => $parameter->id,
                ],
                [
                    'source_topic' => $this->sourceTopicFor($hubImei, $peripheralTypeHex),
                    'source_json_path' => $sourceJsonPath,
                    'source_adapter' => 'imoni',
                    'sequence' => $bindingDefinition['sequence'] ?? 0,
                    'is_active' => true,
                    'metadata' => array_filter([
                        'migration_origin' => $this->organizationSlug(),
                        'legacy_device_uid' => $deviceMetadata['legacy_device_uid'] ?? null,
                        'legacy_source_path' => $bindingDefinition['legacy_source_path'] ?? null,
                        'mutation_expression' => $bindingDefinition['mutation_expression'] ?? null,
                        'decoder' => $bindingDefinition['decoder'] ?? null,
                    ], static fn (mixed $value): bool => $value !== null),
                ],
            );
        }

        $bindingCleanupQuery = DeviceSignalBinding::query()
            ->where('device_id', $device->id);

        if ($expectedParameterIds !== []) {
            $bindingCleanupQuery->whereNotIn('parameter_definition_id', $expectedParameterIds);
        }

        $bindingCleanupQuery->delete();
    }

    protected function decoderFor(string $hubImei, string $peripheralTypeHex, string $sourceJsonPath): ?array
    {
        $normalizedHex = strtoupper($peripheralTypeHex);
        $normalizedPath = trim($sourceJsonPath);

        foreach ($this->specialDecodeProfiles() as $mode => $hubRules) {
            $activePeripheralTypes = $hubRules[$hubImei] ?? null;

            if (! is_array($activePeripheralTypes) || ! in_array($normalizedHex, $activePeripheralTypes, true)) {
                continue;
            }

            if ($mode === 'bigEndianFloat32' && in_array($normalizedPath, ['$.io_1_value', '$.io_2_value'], true)) {
                return [
                    'mode' => 'bigEndianFloat32',
                    'strip_prefix_bytes' => 2,
                ];
            }

            if ($mode === 'twosComplement' && $normalizedPath === '$.io_1_value') {
                return [
                    'mode' => 'twosComplement',
                    'strip_prefix_bytes' => 2,
                ];
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $expectedExternalIds
     */
    protected function cleanupDevices(Organization $organization, string $migrationDeviceType, array $expectedExternalIds): void
    {
        Device::withTrashed()
            ->where('organization_id', $organization->id)
            ->get()
            ->filter(function (Device $device) use ($expectedExternalIds, $migrationDeviceType): bool {
                return ($device->metadata['migration_origin'] ?? null) === $this->organizationSlug()
                    && ($device->metadata['migration_device_type'] ?? null) === $migrationDeviceType
                    && is_string($device->external_id)
                    && ! in_array($device->external_id, $expectedExternalIds, true);
            })
            ->sortByDesc(fn (Device $device): bool => $device->parent_device_id !== null)
            ->each(fn (Device $device): ?bool => $device->forceDelete());
    }

    /**
     * @param  array<array-key, DeviceSchemaVersion>  $schemaVersions
     * @return array<int, int>
     */
    protected function schemaVersionNumbers(array $schemaVersions): array
    {
        return array_values(array_unique(array_map(
            static fn (DeviceSchemaVersion $schemaVersion): int => (int) $schemaVersion->version,
            array_filter($schemaVersions, static fn (mixed $schemaVersion): bool => $schemaVersion instanceof DeviceSchemaVersion),
        )));
    }

    /**
     * @param  array<int, int>  $expectedVersions
     */
    protected function cleanupUnusedDraftSchemaVersions(string $deviceTypeKey, string $schemaName, array $expectedVersions = []): void
    {
        $normalizedExpectedVersions = array_values(array_unique(array_map(
            static fn (mixed $version): int => (int) $version,
            array_filter($expectedVersions, static fn (mixed $version): bool => is_numeric($version)),
        )));

        DeviceSchemaVersion::query()
            ->where('status', 'draft')
            ->whereHas('schema', fn ($schemaQuery) => $schemaQuery
                ->where('name', $schemaName)
                ->whereHas('deviceType', fn ($deviceTypeQuery) => $deviceTypeQuery
                    ->where('key', $deviceTypeKey)
                    ->whereNull('organization_id')))
            ->when(
                $normalizedExpectedVersions !== [],
                fn ($query) => $query->whereNotIn('version', $normalizedExpectedVersions),
            )
            ->get()
            ->reject(fn (DeviceSchemaVersion $schemaVersion): bool => Device::withTrashed()
                ->where('device_schema_version_id', $schemaVersion->id)
                ->exists() || $schemaVersion->telemetryLogs()->exists())
            ->each(fn (DeviceSchemaVersion $schemaVersion) => $schemaVersion->delete());
    }

    protected function schemaVariantKey(string $prefix, mixed ...$parts): string
    {
        $normalizedParts = array_map(
            static fn (mixed $part): string => is_scalar($part) || $part instanceof \Stringable
                ? Str::slug((string) $part, '-')
                : md5(json_encode($part, JSON_THROW_ON_ERROR)),
            $parts,
        );

        return implode('-', array_filter([$prefix, ...$normalizedParts]));
    }
}
