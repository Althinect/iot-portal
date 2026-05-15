<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceSchema\Enums\ParameterCategory;
use App\Domain\DeviceSchema\Enums\ParameterDataType;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Domain\DeviceSchema\Models\ParameterDefinition;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;

class TJIndiaStatusSeeder extends TJIndiaMigrationSeederSupport
{
    private const DEVICE_TYPE_KEY = 'status';

    private const DEVICE_TYPE_NAME = 'Status';

    private const BASE_TOPIC = 'devices/status';

    private const SCHEMA_NAME = 'Status';

    public function run(): void
    {
        $organization = $this->ensureOrganization();
        $hubs = $this->ensureHubs($organization);
        $inventory = TJIndiaMigrationInventory::devicesForType('Status');
        $expectedExternalIds = [];

        $schemaVersion = $this->upsertSchemaVersion(
            deviceTypeKey: self::DEVICE_TYPE_KEY,
            deviceTypeName: self::DEVICE_TYPE_NAME,
            baseTopic: self::BASE_TOPIC,
            schemaName: self::SCHEMA_NAME,
            version: 1,
            status: 'active',
            notes: 'Shared active status contract for TJ India status signals.',
            parameters: [
                [
                    'key' => 'status',
                    'label' => 'Status',
                    'json_path' => '$.status',
                    'type' => ParameterDataType::Integer,
                    'category' => ParameterCategory::State,
                    'required' => false,
                    'is_critical' => true,
                    'validation_rules' => ['min' => 0],
                    'control_ui' => [
                        'state_mappings' => [
                            ['value' => 0, 'label' => 'OFF', 'color' => '#ef4444'],
                            ['value' => 1, 'label' => 'ON', 'color' => '#22c55e'],
                        ],
                    ],
                    'sequence' => 1,
                ],
            ],
        );

        foreach ($inventory as $deviceConfig) {
            $parentDevice = $hubs[$deviceConfig['hub_imei']] ?? null;

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
                    'migration_origin' => TJIndiaMigrationSeeder::ORGANIZATION_SLUG,
                    'migration_role' => 'physical_device',
                    'migration_device_type' => 'Status',
                    'source_adapter' => 'imoni',
                    'schema_variant' => 'status',
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
            $parameter = $topic->parameters()->where('key', 'status')->first();

            if (! $parameter instanceof ParameterDefinition) {
                continue;
            }

            $legacyPath = $deviceConfig['parameter_map']['status'] ?? null;
            $sourceJsonPath = is_string($legacyPath) ? $this->normalizedSourcePath($legacyPath) : null;

            if (! is_string($sourceJsonPath)) {
                continue;
            }

            $this->syncBindings(
                device: $device,
                hubImei: $deviceConfig['hub_imei'],
                peripheralTypeHex: $deviceConfig['peripheral_type_hex'],
                parametersByKey: ['status' => $parameter],
                bindingDefinitions: [
                    'status' => [
                        'source_json_path' => $sourceJsonPath,
                        'legacy_source_path' => $legacyPath,
                        'mutation_expression' => $this->mutationExpressionForParameter($deviceConfig, 'status'),
                        'sequence' => 0,
                    ],
                ],
                deviceMetadata: ['legacy_device_uid' => $deviceConfig['legacy_device_uid']],
            );

            $expectedExternalIds[] = $deviceConfig['external_id'];
        }

        $this->cleanupDevices($organization, 'Status', $expectedExternalIds);
        $this->cleanupUnusedDraftSchemaVersions(self::DEVICE_TYPE_KEY, self::SCHEMA_NAME, [(int) $schemaVersion->version]);
    }
}
