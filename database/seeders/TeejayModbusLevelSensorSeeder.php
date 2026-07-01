<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;

class TeejayModbusLevelSensorSeeder extends TeejayMigrationSeederSupport
{
    private const DEVICE_TYPE_KEY = 'tank_level_sensor';

    private const DEVICE_TYPE_NAME = 'Tank Level Sensor';

    private const BASE_TOPIC = 'storage';

    private const SCHEMA_NAME = 'Tank Level Sensor Contract';

    private const VERSION_OFFSET = 4;

    public function run(): void
    {
        $organization = $this->ensureOrganization();
        $hubs = $this->ensureHubs($organization);
        $inventory = TeejayMigrationInventory::devicesForType('IMoni Modbus Level Sensor');
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
                notes: 'Recovered legacy tank level sensor calibration variant: '.$schemaConfig['variant'],
                parameters: [
                    [
                        'key' => 'level1',
                        'label' => 'Level 1',
                        'json_path' => '$.level1',
                        'type' => ParameterDataType::Decimal,
                        'required' => true,
                        'is_critical' => true,
                        'validation_rules' => ['min' => 0],
                        'mutation_expression' => $schemaConfig['level1_mutation'],
                        'sequence' => 1,
                    ],
                    [
                        'key' => 'level2',
                        'label' => 'Level 2',
                        'json_path' => '$.level2',
                        'type' => ParameterDataType::Decimal,
                        'required' => false,
                        'validation_rules' => ['min' => -1000],
                        'mutation_expression' => $schemaConfig['level2_mutation'],
                        'sequence' => 2,
                    ],
                ],
            );
        }

        foreach ($inventory as $deviceConfig) {
            $parentDevice = $hubs[$deviceConfig['hub_imei']] ?? null;
            $signature = $this->schemaSignatureFor($deviceConfig);
            $schemaVersion = $schemaVersions[$signature] ?? null;

            if (! $parentDevice instanceof Device || ! $schemaVersion instanceof DeviceProfileVersion || ! is_string($deviceConfig['peripheral_type_hex'])) {
                continue;
            }

            $device = $this->upsertChildDevice(
                organization: $organization,
                parentDevice: $parentDevice,
                schemaVersion: $schemaVersion,
                externalId: $deviceConfig['external_id'],
                name: trim($deviceConfig['name']),
                metadata: [
                    'migration_origin' => TeejayMigrationSeeder::ORGANIZATION_SLUG,
                    'migration_role' => 'physical_device',
                    'migration_device_type' => 'IMoni Modbus Level Sensor',
                    'source_adapter' => 'imoni',
                    'schema_variant' => $this->schemaMutationsBySignature([$deviceConfig])[$signature]['variant'] ?? 'tank-level',
                    'legacy_device_uid' => $deviceConfig['legacy_device_uid'],
                    'legacy_virtual_device_id' => $deviceConfig['legacy_virtual_device_id'],
                    'legacy_hub_imei' => $deviceConfig['hub_imei'],
                    'legacy_peripheral_type_hex' => $deviceConfig['peripheral_type_hex'],
                    'legacy_parameter_map' => $deviceConfig['parameter_map'],
                    'legacy_conditional_calibrations' => $deviceConfig['conditional_calibrations'],
                    'legacy_metadata' => $deviceConfig['metadata'],
                ],
            );

            $topic = $schemaVersion->channels()->where('key', 'telemetry')->first();

            if (! $topic instanceof DeviceChannel) {
                continue;
            }

            /** @var array<string, ProfileParameterDefinition> $parametersByKey */
            $parametersByKey = $topic->parameters()->orderBy('sequence')->get()->keyBy('key')->all();
            $bindings = [];

            foreach (['level1', 'level2'] as $index => $parameterKey) {
                $legacyPath = $deviceConfig['parameter_map'][$parameterKey] ?? null;
                $sourceJsonPath = is_string($legacyPath) ? $this->normalizedSourcePath($legacyPath) : null;

                if (! is_string($sourceJsonPath)) {
                    continue;
                }

                $bindings[$parameterKey] = [
                    'source_json_path' => $sourceJsonPath,
                    'legacy_source_path' => $legacyPath,
                    'sequence' => $index,
                    'decoder' => $this->decoderFor(
                        hubImei: $deviceConfig['hub_imei'],
                        peripheralTypeHex: $deviceConfig['peripheral_type_hex'],
                        sourceJsonPath: $sourceJsonPath,
                    ),
                ];
            }

            $this->syncBindings(
                device: $device,
                hubImei: $deviceConfig['hub_imei'],
                peripheralTypeHex: $deviceConfig['peripheral_type_hex'],
                parametersByKey: $parametersByKey,
                bindingDefinitions: $bindings,
                deviceMetadata: ['legacy_device_uid' => $deviceConfig['legacy_device_uid']],
            );

            $expectedExternalIds[] = $deviceConfig['external_id'];
        }

        $this->cleanupDevices($organization, 'IMoni Modbus Level Sensor', $expectedExternalIds);
        $this->cleanupUnusedDraftSchemaVersions(self::DEVICE_TYPE_KEY, self::SCHEMA_NAME, $this->schemaVersionNumbers($schemaVersions));
    }

    /**
     * @param  array<int, array<string, mixed>>  $inventory
     * @return array<string, array{signature: string, variant: string, level1_mutation: array<string, mixed>|null, level2_mutation: array<string, mixed>|null}>
     */
    private function schemaMutationsBySignature(array $inventory): array
    {
        $schemas = [];

        foreach ($inventory as $deviceConfig) {
            $signature = $this->schemaSignatureFor($deviceConfig);

            if (array_key_exists($signature, $schemas)) {
                continue;
            }

            $level1Mutation = $this->mutationExpressionForParameter($deviceConfig, 'level1');
            $level2Mutation = $this->mutationExpressionForParameter($deviceConfig, 'level2');

            $schemas[$signature] = [
                'signature' => $signature,
                'variant' => $this->schemaVariantKey('tank-level', $level1Mutation, $level2Mutation),
                'level1_mutation' => $level1Mutation,
                'level2_mutation' => $level2Mutation,
            ];
        }

        return $schemas;
    }

    /**
     * @param  array<string, mixed>  $deviceConfig
     */
    private function schemaSignatureFor(array $deviceConfig): string
    {
        return md5(json_encode([
            'level1' => $this->mutationExpressionForParameter($deviceConfig, 'level1'),
            'level2' => $this->mutationExpressionForParameter($deviceConfig, 'level2'),
        ], JSON_THROW_ON_ERROR));
    }
}
