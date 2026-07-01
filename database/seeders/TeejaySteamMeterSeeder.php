<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Enums\ParameterCategory;
use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;

class TeejaySteamMeterSeeder extends TeejayMigrationSeederSupport
{
    private const DEVICE_TYPE_KEY = 'steam_meter';

    private const DEVICE_TYPE_NAME = 'Steam Meter';

    private const BASE_TOPIC = 'utilities/steam';

    private const SCHEMA_NAME = 'Steam Meter Contract';

    private const VERSION_OFFSET = 1;

    public function run(): void
    {
        $organization = $this->ensureOrganization();
        $hubs = $this->ensureHubs($organization);
        $inventory = TeejayMigrationInventory::devicesForType('Steam meter');
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
                notes: 'Recovered Teejay steam meter variant: '.$schemaConfig['variant'],
                parameters: [
                    [
                        'key' => 'flow',
                        'label' => 'Flow',
                        'json_path' => '$.flow',
                        'type' => ParameterDataType::Decimal,
                        'required' => true,
                        'is_critical' => true,
                        'validation_rules' => ['min' => 0],
                        'mutation_expression' => $schemaConfig['flow_mutation'],
                        'sequence' => 1,
                    ],
                    [
                        'key' => 'totaliser_count_1',
                        'label' => 'Totaliser Count 1',
                        'json_path' => '$.totaliser_count_1',
                        'type' => ParameterDataType::Integer,
                        'category' => ParameterCategory::Counter,
                        'required' => true,
                        'validation_rules' => ['min' => 0, 'category' => 'counter'],
                        'sequence' => 2,
                    ],
                    [
                        'key' => 'totaliser_count_2',
                        'label' => 'Totaliser Count 2',
                        'json_path' => '$.totaliser_count_2',
                        'type' => ParameterDataType::Integer,
                        'category' => ParameterCategory::Counter,
                        'required' => true,
                        'validation_rules' => ['min' => 0, 'category' => 'counter'],
                        'sequence' => 3,
                    ],
                    [
                        'key' => 'totaliser_count_3',
                        'label' => 'Totaliser Count 3',
                        'json_path' => '$.totaliser_count_3',
                        'type' => ParameterDataType::Integer,
                        'category' => ParameterCategory::Counter,
                        'required' => true,
                        'validation_rules' => ['min' => 0, 'category' => 'counter'],
                        'sequence' => 4,
                    ],
                ],
                derivedParameters: [
                    [
                        'key' => 'totalisedCount',
                        'label' => 'Totalised Count',
                        'data_type' => ParameterDataType::Integer,
                        'expression' => [
                            '+' => [
                                ['*' => [['var' => 'totaliser_count_1'], 1000000]],
                                ['*' => [['var' => 'totaliser_count_2'], 65536]],
                                ['var' => 'totaliser_count_3'],
                            ],
                        ],
                        'dependencies' => ['totaliser_count_1', 'totaliser_count_2', 'totaliser_count_3'],
                        'json_path' => '$.totalisedCount',
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
                name: $deviceConfig['name'],
                metadata: [
                    'migration_origin' => TeejayMigrationSeeder::ORGANIZATION_SLUG,
                    'migration_role' => 'physical_device',
                    'migration_device_type' => 'Steam meter',
                    'source_adapter' => 'imoni',
                    'schema_variant' => $this->schemaMutationsBySignature([$deviceConfig])[$signature]['variant'] ?? 'steam-meter',
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

            foreach (['flow', 'totaliser_count_1', 'totaliser_count_2', 'totaliser_count_3'] as $index => $parameterKey) {
                $legacyPath = $deviceConfig['parameter_map'][$parameterKey] ?? null;
                $sourceJsonPath = is_string($legacyPath) ? $this->normalizedSourcePath($legacyPath) : null;

                if (! is_string($sourceJsonPath)) {
                    continue;
                }

                $bindings[$parameterKey] = [
                    'source_json_path' => $sourceJsonPath,
                    'legacy_source_path' => $legacyPath,
                    'sequence' => $index,
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

        $this->cleanupDevices($organization, 'Steam meter', $expectedExternalIds);
        $this->cleanupUnusedDraftSchemaVersions(self::DEVICE_TYPE_KEY, self::SCHEMA_NAME, $this->schemaVersionNumbers($schemaVersions));
    }

    /**
     * @param  array<int, array<string, mixed>>  $inventory
     * @return array<string, array{signature: string, variant: string, flow_mutation: array<string, mixed>|null}>
     */
    private function schemaMutationsBySignature(array $inventory): array
    {
        $schemas = [];

        foreach ($inventory as $deviceConfig) {
            $signature = $this->schemaSignatureFor($deviceConfig);

            if (array_key_exists($signature, $schemas)) {
                continue;
            }

            $flowMutation = $this->mutationExpressionForParameter($deviceConfig, 'flow');

            $schemas[$signature] = [
                'signature' => $signature,
                'variant' => $this->schemaVariantKey('steam-meter', $flowMutation),
                'flow_mutation' => $flowMutation,
            ];
        }

        return $schemas;
    }

    /**
     * @param  array<string, mixed>  $deviceConfig
     */
    private function schemaSignatureFor(array $deviceConfig): string
    {
        return md5(json_encode($this->mutationExpressionForParameter($deviceConfig, 'flow'), JSON_THROW_ON_ERROR));
    }
}
