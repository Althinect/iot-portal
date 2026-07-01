<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Enums\MetricUnit;
use App\Domain\DeviceProfile\Enums\ParameterCategory;
use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;

class TeejayWaterFlowVolumeSeeder extends TeejayMigrationSeederSupport
{
    private const DEVICE_TYPE_KEY = 'water_flow_meter';

    private const DEVICE_TYPE_NAME = 'Water Flow Meter';

    private const BASE_TOPIC = 'water';

    private const SCHEMA_NAME = 'Water Flow Meter Contract';

    public function run(): void
    {
        $organization = $this->ensureOrganization();
        $hubs = $this->ensureHubs($organization);

        $schemaVersion = $this->upsertSchemaVersion(
            deviceTypeKey: self::DEVICE_TYPE_KEY,
            deviceTypeName: self::DEVICE_TYPE_NAME,
            baseTopic: self::BASE_TOPIC,
            schemaName: self::SCHEMA_NAME,
            parameters: [
                [
                    'key' => 'flow',
                    'label' => 'Flow',
                    'json_path' => '$.flow',
                    'type' => ParameterDataType::Decimal,
                    'unit' => MetricUnit::LitersPerMinute->value,
                    'required' => true,
                    'is_critical' => true,
                    'validation_rules' => ['min' => 0, 'category' => 'static'],
                    'mutation_expression' => ['*' => [['var' => 'val'], 3600]],
                    'sequence' => 1,
                ],
                [
                    'key' => 'volume',
                    'label' => 'Volume',
                    'json_path' => '$.volume',
                    'type' => ParameterDataType::Decimal,
                    'unit' => MetricUnit::CubicMeters->value,
                    'category' => ParameterCategory::Counter,
                    'required' => true,
                    'validation_rules' => ['min' => 0, 'category' => 'counter'],
                    'mutation_expression' => [
                        'if' => [
                            ['>' => [['var' => 'val'], 4294967295]],
                            ['decode_big_endian_float' => [['var' => 'val'], 8]],
                            ['var' => 'val'],
                        ],
                    ],
                    'sequence' => 2,
                ],
            ],
            notes: 'Recovered Teejay water flow and volume contract with Laravel-side decode profiles.',
        );

        $topic = $schemaVersion->channels()->where('key', 'telemetry')->first();

        if (! $topic instanceof DeviceChannel) {
            throw new \RuntimeException('Teejay water flow telemetry topic could not be resolved.');
        }

        /** @var array<string, ProfileParameterDefinition> $parametersByKey */
        $parametersByKey = $topic->parameters()->orderBy('sequence')->get()->keyBy('key')->all();
        $expectedExternalIds = [];

        foreach (TeejayMigrationInventory::devicesForType('Water Flow and Volume') as $deviceConfig) {
            $parentDevice = $hubs[$deviceConfig['hub_imei']] ?? null;

            if (! $parentDevice instanceof Device || ! is_string($deviceConfig['peripheral_type_hex'])) {
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
                    'migration_device_type' => 'Water Flow and Volume',
                    'source_adapter' => 'imoni',
                    'schema_variant' => 'water-flow-standard',
                    'legacy_device_uid' => $deviceConfig['legacy_device_uid'],
                    'legacy_virtual_device_id' => $deviceConfig['legacy_virtual_device_id'],
                    'legacy_hub_imei' => $deviceConfig['hub_imei'],
                    'legacy_peripheral_type_hex' => $deviceConfig['peripheral_type_hex'],
                    'legacy_parameter_map' => $deviceConfig['parameter_map'],
                    'legacy_conditional_calibrations' => $deviceConfig['conditional_calibrations'],
                    'legacy_metadata' => $deviceConfig['metadata'],
                ],
            );

            $bindings = [];

            foreach (['flow', 'volume'] as $index => $parameterKey) {
                $legacyPath = $deviceConfig['parameter_map'][$parameterKey] ?? null;
                $sourceJsonPath = is_string($legacyPath) ? $this->normalizedSourcePath($legacyPath) : null;

                if (! is_string($sourceJsonPath)) {
                    continue;
                }

                $decoder = $this->decoderFor(
                    hubImei: $deviceConfig['hub_imei'],
                    peripheralTypeHex: $deviceConfig['peripheral_type_hex'],
                    sourceJsonPath: $sourceJsonPath,
                );

                if (($decoder['mode'] ?? null) === 'bigEndianFloat32' && $parameterKey === 'volume') {
                    $decoder['mode'] = 'bigEndianFloatAuto';
                }

                $bindings[$parameterKey] = [
                    'source_json_path' => $sourceJsonPath,
                    'legacy_source_path' => $legacyPath,
                    'sequence' => $index,
                    'decoder' => $decoder,
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

        $this->cleanupDevices($organization, 'Water Flow and Volume', $expectedExternalIds);
    }
}
