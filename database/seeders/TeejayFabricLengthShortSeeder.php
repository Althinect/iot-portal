<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceSchema\Enums\ParameterCategory;
use App\Domain\DeviceSchema\Enums\ParameterDataType;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Domain\DeviceSchema\Models\ParameterDefinition;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;

class TeejayFabricLengthShortSeeder extends TeejayMigrationSeederSupport
{
    private const DEVICE_TYPE_KEY = 'fabric_length_counter';

    private const DEVICE_TYPE_NAME = 'Fabric Length Counter';

    private const BASE_TOPIC = 'production/fabric-length';

    private const SCHEMA_NAME = 'Fabric Length Contract';

    private const VERSION_OFFSET = 12;

    public function run(): void
    {
        $organization = $this->ensureOrganization();
        $hubs = $this->ensureHubs($organization);
        $inventory = TeejayMigrationInventory::devicesForType('Fabric Length(Short)');
        $schemaVersions = [];
        $expectedExternalIds = [];

        foreach (array_values($this->schemaMutationsBySignature($inventory)) as $index => $schemaConfig) {
            $schemaVersions[$schemaConfig['signature']] = $this->upsertSchemaVersion(
                deviceTypeKey: self::DEVICE_TYPE_KEY,
                deviceTypeName: self::DEVICE_TYPE_NAME,
                baseTopic: self::BASE_TOPIC,
                schemaName: self::SCHEMA_NAME,
                version: self::VERSION_OFFSET + $index,
                status: 'draft',
                notes: 'Recovered legacy short fabric length calibration variant: '.$schemaConfig['variant'],
                parameters: [
                    [
                        'key' => 'length',
                        'label' => 'Fabric Length',
                        'json_path' => '$.length',
                        'type' => ParameterDataType::Decimal,
                        'category' => ParameterCategory::Counter,
                        'required' => true,
                        'is_critical' => true,
                        'validation_rules' => ['min' => -100000, 'category' => 'counter'],
                        'mutation_expression' => $schemaConfig['length_mutation'],
                        'sequence' => 1,
                    ],
                ],
            );
        }

        foreach ($inventory as $deviceConfig) {
            $parentDevice = $hubs[$deviceConfig['hub_imei']] ?? null;
            $signature = $this->schemaSignatureFor($deviceConfig);
            $schemaVersion = $schemaVersions[$signature] ?? null;

            if (! $parentDevice instanceof Device || ! $schemaVersion instanceof DeviceSchemaVersion || ! is_string($deviceConfig['peripheral_type_hex'])) {
                continue;
            }

            $device = $this->upsertChildDevice(
                organization: $organization,
                parentDevice: $parentDevice,
                schemaVersion: $schemaVersion,
                externalId: $deviceConfig['external_id'],
                name: $deviceConfig['name'],
                metadata: [
                    'migration_origin' => TeejayMigrationSeeder::ORGANIZATION_SLUG,
                    'migration_role' => 'physical_device',
                    'migration_device_type' => 'Fabric Length(Short)',
                    'source_adapter' => 'imoni',
                    'schema_variant' => $this->schemaMutationsBySignature([$deviceConfig])[$signature]['variant'] ?? 'fabric-length-short',
                    'legacy_device_uid' => $deviceConfig['legacy_device_uid'],
                    'legacy_virtual_device_id' => $deviceConfig['legacy_virtual_device_id'],
                    'legacy_hub_imei' => $deviceConfig['hub_imei'],
                    'legacy_peripheral_type_hex' => $deviceConfig['peripheral_type_hex'],
                    'legacy_parameter_map' => $deviceConfig['parameter_map'],
                    'legacy_conditional_calibrations' => $deviceConfig['conditional_calibrations'],
                    'legacy_metadata' => $deviceConfig['metadata'],
                ],
            );

            $topic = $schemaVersion->topics()->where('key', 'telemetry')->first();

            if (! $topic instanceof SchemaVersionTopic) {
                continue;
            }

            /** @var ParameterDefinition|null $parameter */
            $parameter = $topic->parameters()->where('key', 'length')->first();

            if (! $parameter instanceof ParameterDefinition) {
                continue;
            }

            $legacyPath = $this->primaryLegacyPath($deviceConfig);
            $sourceJsonPath = is_string($legacyPath) ? $this->normalizedSourcePath($legacyPath) : null;

            if (! is_string($sourceJsonPath)) {
                continue;
            }

            $this->syncBindings(
                device: $device,
                hubImei: $deviceConfig['hub_imei'],
                peripheralTypeHex: $deviceConfig['peripheral_type_hex'],
                parametersByKey: ['length' => $parameter],
                bindingDefinitions: [
                    'length' => [
                        'source_json_path' => $sourceJsonPath,
                        'legacy_source_path' => $legacyPath,
                        'sequence' => 0,
                        'decoder' => $this->decoderFor(
                            hubImei: $deviceConfig['hub_imei'],
                            peripheralTypeHex: $deviceConfig['peripheral_type_hex'],
                            sourceJsonPath: $sourceJsonPath,
                        ),
                    ],
                ],
                deviceMetadata: ['legacy_device_uid' => $deviceConfig['legacy_device_uid']],
            );

            $expectedExternalIds[] = $deviceConfig['external_id'];
        }

        $this->cleanupDevices($organization, 'Fabric Length(Short)', $expectedExternalIds);
        $this->cleanupUnusedDraftSchemaVersions(self::DEVICE_TYPE_KEY, self::SCHEMA_NAME, $this->schemaVersionNumbers($schemaVersions));
    }

    /**
     * @param  array<int, array<string, mixed>>  $inventory
     * @return array<string, array{signature: string, variant: string, length_mutation: array<string, mixed>|null}>
     */
    private function schemaMutationsBySignature(array $inventory): array
    {
        $schemas = [];

        foreach ($inventory as $deviceConfig) {
            $signature = $this->schemaSignatureFor($deviceConfig);

            if (array_key_exists($signature, $schemas)) {
                continue;
            }

            $lengthMutation = $this->mutationExpressionForParameter($deviceConfig, 'length');

            $schemas[$signature] = [
                'signature' => $signature,
                'variant' => $this->schemaVariantKey('fabric-length-short', $lengthMutation),
                'length_mutation' => $lengthMutation,
            ];
        }

        return $schemas;
    }

    /**
     * @param  array<string, mixed>  $deviceConfig
     */
    private function schemaSignatureFor(array $deviceConfig): string
    {
        return md5(json_encode($this->mutationExpressionForParameter($deviceConfig, 'length'), JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $deviceConfig
     */
    private function primaryLegacyPath(array $deviceConfig): ?string
    {
        $parameterMap = $deviceConfig['parameter_map'] ?? [];

        if (! is_array($parameterMap)) {
            return null;
        }

        foreach (['length', 'length_raw', 'ioid1'] as $parameterKey) {
            $candidate = $parameterMap[$parameterKey] ?? null;

            if (is_string($candidate) && trim($candidate) !== '') {
                return $candidate;
            }
        }

        return null;
    }
}
