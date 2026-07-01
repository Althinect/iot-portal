<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;

class TeejayPressureSeeder extends TeejayMigrationSeederSupport
{
    private const DEVICE_TYPE_KEY = 'pressure_sensor';

    private const DEVICE_TYPE_NAME = 'Pressure Sensor';

    private const BASE_TOPIC = 'pressure';

    private const SCHEMA_NAME = 'Pressure Sensor Contract';

    private const VERSION_OFFSET = 1;

    public function run(): void
    {
        $organization = $this->ensureOrganization();
        $hubs = $this->ensureHubs($organization);
        $inventory = TeejayMigrationInventory::devicesForType('Preassure');
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
                notes: 'Recovered Teejay pressure calibration variant: '.$schemaConfig['variant'],
                parameters: [
                    [
                        'key' => 'pressure',
                        'label' => 'Pressure',
                        'json_path' => '$.pressure',
                        'type' => ParameterDataType::Decimal,
                        'required' => true,
                        'is_critical' => true,
                        'validation_rules' => ['min' => 0],
                        'mutation_expression' => $schemaConfig['pressure_mutation'],
                        'sequence' => 1,
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
                    'migration_device_type' => 'Preassure',
                    'source_adapter' => 'imoni',
                    'schema_variant' => $this->schemaMutationsBySignature([$deviceConfig])[$signature]['variant'] ?? 'pressure',
                    'legacy_device_uid' => $deviceConfig['legacy_device_uid'],
                    'legacy_virtual_device_id' => $deviceConfig['legacy_virtual_device_id'],
                    'legacy_hub_imei' => $deviceConfig['hub_imei'],
                    'legacy_peripheral_type_hex' => $deviceConfig['peripheral_type_hex'],
                    'legacy_parameter_map' => $deviceConfig['parameter_map'],
                    'legacy_calibrations' => $deviceConfig['calibrations'],
                    'legacy_conditional_calibrations' => $deviceConfig['conditional_calibrations'],
                    'legacy_metadata' => $deviceConfig['metadata'],
                ],
            );

            $topic = $schemaVersion->channels()->where('key', 'telemetry')->first();

            if (! $topic instanceof DeviceChannel) {
                continue;
            }

            /** @var ProfileParameterDefinition|null $parameter */
            $parameter = $topic->parameters()->where('key', 'pressure')->first();

            if (! $parameter instanceof ProfileParameterDefinition) {
                continue;
            }

            $legacyPath = $deviceConfig['parameter_map']['preassure'] ?? null;
            $sourceJsonPath = is_string($legacyPath) ? $this->normalizedSourcePath($legacyPath) : null;

            if (! is_string($sourceJsonPath)) {
                continue;
            }

            $this->syncBindings(
                device: $device,
                hubImei: $deviceConfig['hub_imei'],
                peripheralTypeHex: $deviceConfig['peripheral_type_hex'],
                parametersByKey: ['pressure' => $parameter],
                bindingDefinitions: [
                    'pressure' => [
                        'source_json_path' => $sourceJsonPath,
                        'legacy_source_path' => $legacyPath,
                        'sequence' => 0,
                    ],
                ],
                deviceMetadata: ['legacy_device_uid' => $deviceConfig['legacy_device_uid']],
            );

            $expectedExternalIds[] = $deviceConfig['external_id'];
        }

        $this->cleanupDevices($organization, 'Preassure', $expectedExternalIds);
        $this->cleanupUnusedDraftSchemaVersions(self::DEVICE_TYPE_KEY, self::SCHEMA_NAME, $this->schemaVersionNumbers($schemaVersions));
    }

    /**
     * @param  array<int, array<string, mixed>>  $inventory
     * @return array<string, array{signature: string, variant: string, pressure_mutation: array<string, mixed>|null}>
     */
    private function schemaMutationsBySignature(array $inventory): array
    {
        $schemas = [];

        foreach ($inventory as $deviceConfig) {
            $signature = $this->schemaSignatureFor($deviceConfig);

            if (array_key_exists($signature, $schemas)) {
                continue;
            }

            $pressureMutation = $this->mutationExpressionForParameter(
                ['conditional_calibrations' => ['pressure' => $deviceConfig['conditional_calibrations']['preassure'] ?? null], 'calibrations' => ['pressure' => $deviceConfig['calibrations']['preassure'] ?? null]],
                'pressure',
            );

            $schemas[$signature] = [
                'signature' => $signature,
                'variant' => $this->schemaVariantKey('pressure', $pressureMutation),
                'pressure_mutation' => $pressureMutation,
            ];
        }

        return $schemas;
    }

    /**
     * @param  array<string, mixed>  $deviceConfig
     */
    private function schemaSignatureFor(array $deviceConfig): string
    {
        return md5(json_encode(
            $this->mutationExpressionForParameter(
                ['conditional_calibrations' => ['pressure' => $deviceConfig['conditional_calibrations']['preassure'] ?? null], 'calibrations' => ['pressure' => $deviceConfig['calibrations']['preassure'] ?? null]],
                'pressure',
            ),
            JSON_THROW_ON_ERROR,
        ));
    }
}
