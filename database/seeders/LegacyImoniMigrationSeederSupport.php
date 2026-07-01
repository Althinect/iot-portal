<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\DataIngestion\Models\DeviceSignalBinding;
use App\Domain\DeviceManagement\Enums\MqttSecurityMode;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\ValueObjects\Protocol\MqttProtocolConfig;
use App\Domain\DeviceProfile\Enums\ChannelDirection;
use App\Domain\DeviceProfile\Enums\ChannelPurpose;
use App\Domain\DeviceProfile\Enums\ChannelTransport;
use App\Domain\DeviceProfile\Enums\ParameterCategory;
use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Enums\Protocol;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\DeviceProfile;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Models\ProfileDerivedParameterDefinition;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use App\Domain\Shared\Models\Organization;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
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

    protected function upsertHubSchemaVersion(): DeviceProfileVersion
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
            purpose: ChannelPurpose::State,
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
                    'device_profile_version_id' => $this->profileVersionForSchemaVersion($schemaVersion)->id,
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
        ChannelPurpose $purpose = ChannelPurpose::Telemetry,
        int $version = 1,
        string $status = 'active',
        string $notes = 'Legacy iMoni migration schema.',
        array $derivedParameters = [],
        ?array $virtualStandardProfile = null,
    ): DeviceProfileVersion {
        $deviceType = DeviceProfile::query()->updateOrCreate(
            [
                'organization_id' => null,
                'key' => $deviceTypeKey,
            ],
            [
                'name' => $deviceTypeName,
            ],
        );

        $schema = $deviceType;

        $schemaVersion = DeviceProfileVersion::query()->firstOrCreate(
            [
                'device_profile_id' => $schema->id,
                'version' => $version,
            ],
            [
                'status' => $status,
                'virtual_standard_profile' => $virtualStandardProfile,
                'notes' => $notes,
            ],
        );

        $schemaVersion->fill([
            'status' => $status,
            'virtual_standard_profile' => $virtualStandardProfile,
            'notes' => $notes,
        ])->save();

        $profile = DeviceProfile::query()->updateOrCreate(
            [
                'organization_id' => null,
                'key' => $deviceTypeKey,
            ],
            [
                'name' => $deviceTypeName,
                'tags' => null,
            ],
        );

        $profileVersion = DeviceProfileVersion::query()->updateOrCreate(
            [
                'device_profile_id' => $profile->id,
                'version' => $version,
            ],
            [
                'status' => $status,
                'protocol' => Protocol::Mqtt,
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
                'notes' => $notes,
            ],
        );

        $topic = DeviceChannel::query()->updateOrCreate(
            [
                'device_profile_version_id' => $profileVersion->id,
                'key' => $topicKey,
            ],
            [
                'label' => $topicLabel,
                'direction' => ChannelDirection::Publish,
                'purpose' => $purpose,
                'address' => $topicSuffix,
                'qos' => 1,
                'retain' => false,
                'sequence' => 0,
            ],
        );

        $channel = DeviceChannel::query()->updateOrCreate(
            [
                'device_profile_version_id' => $profileVersion->id,
                'key' => $topicKey,
            ],
            [
                'label' => $topicLabel,
                'direction' => ChannelDirection::Publish,
                'purpose' => $purpose === ChannelPurpose::State ? ChannelPurpose::State : ChannelPurpose::Telemetry,
                'transport' => ChannelTransport::Mqtt,
                'address' => $topicSuffix,
                'qos' => 1,
                'retain' => false,
                'sequence' => 0,
            ],
        );

        foreach ($parameters as $parameter) {
            ProfileParameterDefinition::query()->updateOrCreate(
                [
                    'device_channel_id' => $topic->id,
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

            ProfileParameterDefinition::query()->updateOrCreate(
                [
                    'device_channel_id' => $channel->id,
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

        ProfileParameterDefinition::query()
            ->where('device_channel_id', $topic->id)
            ->when(
                $parameterKeys !== [],
                fn ($query) => $query->whereNotIn('key', $parameterKeys),
            )
            ->delete();

        ProfileParameterDefinition::query()
            ->where('device_channel_id', $channel->id)
            ->when(
                $parameterKeys !== [],
                fn ($query) => $query->whereNotIn('key', $parameterKeys),
            )
            ->delete();

        foreach ($derivedParameters as $derivedParameter) {
            ProfileDerivedParameterDefinition::query()->updateOrCreate(
                [
                    'device_profile_version_id' => $profileVersion->id,
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

            ProfileDerivedParameterDefinition::query()->updateOrCreate(
                [
                    'device_profile_version_id' => $profileVersion->id,
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

        $derivedParameterCleanupQuery = ProfileDerivedParameterDefinition::query()
            ->where('device_profile_version_id', $schemaVersion->id);

        if ($derivedKeys !== []) {
            $derivedParameterCleanupQuery->whereNotIn('key', $derivedKeys);
        }

        $derivedParameterCleanupQuery->delete();

        $profileDerivedParameterCleanupQuery = ProfileDerivedParameterDefinition::query()
            ->where('device_profile_version_id', $profileVersion->id);

        if ($derivedKeys !== []) {
            $profileDerivedParameterCleanupQuery->whereNotIn('key', $derivedKeys);
        }

        $profileDerivedParameterCleanupQuery->delete();

        /** @var DeviceProfileVersion $freshSchemaVersion */
        $freshSchemaVersion = $schemaVersion->fresh(['profile']);

        return $freshSchemaVersion;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    protected function upsertChildDevice(
        Organization $organization,
        Device $parentDevice,
        DeviceProfileVersion $schemaVersion,
        string $externalId,
        string $name,
        array $metadata,
    ): Device {
        $profileVersion = $this->profileVersionForSchemaVersion($schemaVersion);

        /** @var Device $device */
        $device = Device::withTrashed()->updateOrCreate(
            [
                'organization_id' => $organization->id,
                'external_id' => $externalId,
            ],
            [
                'device_profile_version_id' => $profileVersion->id,
                'parent_device_id' => $parentDevice->id,
                'name' => $this->displayNameForProfile($name, $profileVersion),
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
        DeviceProfileVersion $schemaVersion,
        string $externalId,
        string $name,
        array $metadata,
    ): Device {
        $profileVersion = $this->profileVersionForSchemaVersion($schemaVersion);

        /** @var Device $device */
        $device = Device::withTrashed()->updateOrCreate(
            [
                'organization_id' => $organization->id,
                'external_id' => $externalId,
            ],
            [
                'device_profile_version_id' => $profileVersion->id,
                'parent_device_id' => null,
                'name' => $this->displayNameForProfile($name, $profileVersion),
                'metadata' => $metadata,
                'is_active' => true,
                'connection_state' => 'offline',
                'last_seen_at' => null,
                'deleted_at' => null,
            ],
        );

        return $device;
    }

    protected function displayNameForProfile(string $name, DeviceProfileVersion $profileVersion): string
    {
        return $name;
    }

    protected function energyProfileDisplayName(string $name, DeviceProfileVersion $profileVersion): string
    {
        $profileVersion->loadMissing('profile');

        if ($profileVersion->profile?->key !== 'energy_meter') {
            return $name;
        }

        $trimmedName = trim($name);

        if ($trimmedName === '' || preg_match('/\benergy$/i', $trimmedName) === 1) {
            return $name;
        }

        return "{$trimmedName} Energy";
    }

    protected function sourceTopicFor(string $hubImei, string $peripheralTypeHex): string
    {
        return 'migration/source/imoni/'.$hubImei.'/'.strtoupper($peripheralTypeHex).'/telemetry';
    }

    protected function profileVersionForSchemaVersion(DeviceProfileVersion $schemaVersion): DeviceProfileVersion
    {
        $schemaVersion->loadMissing('profile');

        $deviceType = $schemaVersion->profile;

        if (! $deviceType instanceof DeviceProfile) {
            throw new \RuntimeException('Unable to resolve mirrored profile version for schema version.');
        }

        $profileVersion = DeviceProfileVersion::query()
            ->where('version', (int) $schemaVersion->version)
            ->whereHas('profile', fn ($query) => $query
                ->whereNull('organization_id')
                ->where('key', $deviceType->key))
            ->first();

        if (! $profileVersion instanceof DeviceProfileVersion) {
            throw new \RuntimeException("Mirrored profile version [{$deviceType->key}:{$schemaVersion->version}] was not found.");
        }

        return $profileVersion;
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
        $mutationExpression = null;
        $conditionalCalibrations = $deviceConfig['conditional_calibrations'] ?? [];

        if (is_array($conditionalCalibrations)) {
            $expression = $conditionalCalibrations[$parameterKey] ?? null;

            if (is_string($expression) && trim($expression) !== '') {
                $mutationExpression = $this->normalizeMutationExpressionVariables(
                    json_decode($expression, true, 512, JSON_THROW_ON_ERROR),
                );
            }
        }

        if ($mutationExpression !== null) {
            return $this->applySpecialMutationDecoder($deviceConfig, $parameterKey, $mutationExpression);
        }

        $calibrations = $deviceConfig['calibrations'] ?? [];

        if (! is_array($calibrations)) {
            return $this->applySpecialMutationDecoder($deviceConfig, $parameterKey, null);
        }

        $calibration = $calibrations[$parameterKey] ?? null;

        if (! is_string($calibration) || trim($calibration) === '') {
            return $this->applySpecialMutationDecoder($deviceConfig, $parameterKey, null);
        }

        $normalized = str_replace(' ', '', $calibration);
        $matches = [];

        if (preg_match('/^[A-Za-z0-9_]+\/([0-9.]+)$/', $normalized, $matches) === 1) {
            $mutationExpression = [
                '/' => [
                    ['var' => 'val'],
                    (float) $matches[1],
                ],
            ];
        }

        if (preg_match('/^[A-Za-z0-9_]+\*([0-9.]+)$/', $normalized, $matches) === 1) {
            $mutationExpression = [
                '*' => [
                    ['var' => 'val'],
                    (float) $matches[1],
                ],
            ];
        }

        return $this->applySpecialMutationDecoder($deviceConfig, $parameterKey, $mutationExpression);
    }

    protected function applySpecialMutationDecoder(array $deviceConfig, string $parameterKey, ?array $mutationExpression): ?array
    {
        $decoderExpression = $this->specialMutationDecoderExpression($deviceConfig, $parameterKey);

        if ($decoderExpression === null) {
            return $mutationExpression;
        }

        if ($mutationExpression === null) {
            return $decoderExpression;
        }

        return $this->replaceMutationValueVariable($mutationExpression, $decoderExpression);
    }

    protected function specialMutationDecoderExpression(array $deviceConfig, string $parameterKey): ?array
    {
        $hubImei = $deviceConfig['hub_imei'] ?? null;
        $peripheralTypeHex = $deviceConfig['peripheral_type_hex'] ?? null;
        $parameterMap = $deviceConfig['parameter_map'] ?? [];

        if (! is_string($hubImei) || ! is_string($peripheralTypeHex) || ! is_array($parameterMap)) {
            return null;
        }

        $legacyPath = $parameterMap[$parameterKey] ?? null;

        if (! is_string($legacyPath)) {
            return null;
        }

        $normalizedPath = $this->normalizedSourcePath($legacyPath);

        if ($normalizedPath !== '$.io_1_value') {
            return null;
        }

        $activePeripheralTypes = $this->specialDecodeProfiles()['twosComplement'][$hubImei] ?? null;

        if (! is_array($activePeripheralTypes) || ! in_array(strtoupper($peripheralTypeHex), $activePeripheralTypes, true)) {
            return null;
        }

        return [
            'decode_twos_complement' => [
                ['var' => 'val'],
                32,
            ],
        ];
    }

    protected function replaceMutationValueVariable(mixed $expression, array $replacement): mixed
    {
        if (! is_array($expression)) {
            return $expression;
        }

        if (array_key_exists('var', $expression) && $expression['var'] === 'val') {
            return $replacement;
        }

        foreach ($expression as $key => $value) {
            $expression[$key] = $this->replaceMutationValueVariable($value, $replacement);
        }

        return $expression;
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
     * @param  array<string, ProfileParameterDefinition>  $parametersByKey
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
        $channel = DeviceChannel::query()
            ->where('device_profile_version_id', $device->device_profile_version_id)
            ->where('key', 'telemetry')
            ->first()
            ?? DeviceChannel::query()
                ->where('device_profile_version_id', $device->device_profile_version_id)
                ->where('key', 'heartbeat')
                ->first();

        if (! $channel instanceof DeviceChannel) {
            return;
        }

        $expectedParameterKeys = [];

        foreach ($bindingDefinitions as $parameterKey => $bindingDefinition) {
            $parameter = $parametersByKey[$parameterKey] ?? null;

            if (! $parameter instanceof ProfileParameterDefinition) {
                continue;
            }

            $sourceJsonPath = $bindingDefinition['source_json_path'] ?? null;

            if (! is_string($sourceJsonPath) || trim($sourceJsonPath) === '') {
                continue;
            }

            $expectedParameterKeys[] = $parameter->key;

            DeviceSignalBinding::query()->updateOrCreate(
                [
                    'device_id' => $device->id,
                    'device_channel_id' => $channel->id,
                    'parameter_key' => $parameter->key,
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

        if ($expectedParameterKeys !== []) {
            $bindingCleanupQuery->whereNotIn('parameter_key', $expectedParameterKeys);
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
     * @param  array<array-key, DeviceProfileVersion>  $schemaVersions
     * @return array<int, int>
     */
    protected function schemaVersionNumbers(array $schemaVersions): array
    {
        return array_values(array_unique(array_map(
            static fn (DeviceProfileVersion $schemaVersion): int => (int) $schemaVersion->version,
            array_filter($schemaVersions, static fn (mixed $schemaVersion): bool => $schemaVersion instanceof DeviceProfileVersion),
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

        DeviceProfileVersion::query()
            ->where('status', 'draft')
            ->whereHas('profile', fn ($profileQuery) => $profileQuery
                ->where('key', $deviceTypeKey)
                ->whereNull('organization_id'))
            ->when(
                $normalizedExpectedVersions !== [],
                fn ($query) => $query->whereNotIn('version', $normalizedExpectedVersions),
            )
            ->get()
            ->reject(function (DeviceProfileVersion $schemaVersion): bool {
                try {
                    $profileVersion = $this->profileVersionForSchemaVersion($schemaVersion);
                } catch (\RuntimeException) {
                    return false;
                }

                return Device::withTrashed()
                    ->where('device_profile_version_id', $profileVersion->id)
                    ->exists()
                    || DeviceTelemetryLog::query()
                        ->where('device_profile_version_id', $profileVersion->id)
                        ->exists();
            })
            ->each(fn (DeviceProfileVersion $schemaVersion) => $schemaVersion->delete());
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
