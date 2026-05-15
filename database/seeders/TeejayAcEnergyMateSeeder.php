<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\DataIngestion\Models\DeviceSignalBinding;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceSchema\Enums\MetricUnit;
use App\Domain\DeviceSchema\Enums\ParameterCategory;
use App\Domain\DeviceSchema\Enums\ParameterDataType;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Domain\DeviceSchema\Models\ParameterDefinition;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;

class TeejayAcEnergyMateSeeder extends TeejayMigrationSeederSupport
{
    private const DEVICE_TYPE_KEY = 'energy_meter';

    private const DEVICE_TYPE_NAME = 'Energy Meter';

    private const BASE_TOPIC = 'energy';

    private const SCHEMA_NAME = 'Energy Meter Contract';

    private const VERSION_OFFSET = 4;

    private const COMPRESSOR_STATUS_THRESHOLD = 10;

    public function run(): void
    {
        $organization = $this->ensureOrganization();
        $hubs = $this->ensureHubs($organization);
        $inventory = TeejayMigrationInventory::devicesForType('AC Energy Mate');
        $schemaVersions = [];
        $parametersBySchemaSignature = [];

        foreach (array_values($this->schemaConfigurationsBySignature($inventory)) as $index => $schemaConfig) {
            $schemaVersion = $this->upsertSchemaVersion(
                deviceTypeKey: self::DEVICE_TYPE_KEY,
                deviceTypeName: self::DEVICE_TYPE_NAME,
                baseTopic: self::BASE_TOPIC,
                schemaName: self::SCHEMA_NAME,
                version: self::VERSION_OFFSET + $index,
                status: 'draft',
                notes: 'Recovered legacy AC Energy Mate contract variant: '.$schemaConfig['variant'],
                parameters: $this->parametersForSchema(),
                derivedParameters: $schemaConfig['derived_parameters'],
            );

            $topic = $schemaVersion->topics()->where('key', 'telemetry')->first();

            if (! $topic instanceof SchemaVersionTopic) {
                throw new \RuntimeException('Teejay AC Energy Mate telemetry topic could not be resolved.');
            }

            $schemaVersions[$schemaConfig['signature']] = $schemaVersion;
            $parametersBySchemaSignature[$schemaConfig['signature']] = $topic->parameters()->orderBy('sequence')->get()->keyBy('key')->all();
        }

        $expectedExternalIds = [];

        foreach ($inventory as $deviceConfig) {
            $parentDevice = $hubs[$deviceConfig['hub_imei']] ?? null;
            $signature = $this->schemaSignatureFor($deviceConfig);
            $schemaVersion = $schemaVersions[$signature] ?? null;
            $schemaConfig = $this->schemaConfigurationsBySignature([$deviceConfig])[$signature] ?? null;

            if (! $parentDevice instanceof Device || ! $schemaVersion instanceof DeviceSchemaVersion || ! is_array($schemaConfig) || ! is_string($deviceConfig['peripheral_type_hex'])) {
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
                    'migration_device_type' => 'AC Energy Mate',
                    'source_adapter' => 'imoni',
                    'schema_variant' => $schemaConfig['variant'],
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

            /** @var array<string, ParameterDefinition> $parametersByKey */
            $parametersByKey = $parametersBySchemaSignature[$signature] ?? [];
            $bindings = [];

            foreach (array_keys($parametersByKey) as $parameterKey) {
                $legacyPath = $deviceConfig['parameter_map'][$parameterKey] ?? null;
                $sourceJsonPath = is_string($legacyPath) ? $this->normalizedSourcePath($legacyPath) : null;

                if (! is_string($sourceJsonPath)) {
                    continue;
                }

                $bindings[$parameterKey] = [
                    'source_json_path' => $sourceJsonPath,
                    'legacy_source_path' => $legacyPath,
                    'sequence' => count($bindings),
                ];
            }

            $this->syncBindings(
                device: $device,
                hubImei: $deviceConfig['hub_imei'],
                peripheralTypeHex: $deviceConfig['peripheral_type_hex'],
                parametersByKey: $parametersByKey,
                bindingDefinitions: $bindings,
                deviceMetadata: [
                    'legacy_device_uid' => $deviceConfig['legacy_device_uid'],
                ],
            );

            $expectedExternalIds[] = $deviceConfig['external_id'];
        }

        $this->cleanupDevices($organization, 'AC Energy Mate', $expectedExternalIds);
        $this->cleanupUnusedDraftSchemaVersions(self::DEVICE_TYPE_KEY, self::SCHEMA_NAME, $this->schemaVersionNumbers($schemaVersions));

        DeviceSignalBinding::query()
            ->whereHas('device', fn ($query) => $query
                ->where('organization_id', $organization->id)
                ->whereHas('deviceType', fn ($typeQuery) => $typeQuery->where('key', self::DEVICE_TYPE_KEY)))
            ->where('source_adapter', 'imoni');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parametersForSchema(): array
    {
        $defaultMutations = $this->defaultMutationExpressions();

        return [
            [
                'key' => 'TotalEnergy',
                'label' => 'Total Energy',
                'json_path' => '$.TotalEnergy',
                'type' => ParameterDataType::Decimal,
                'unit' => MetricUnit::KilowattHours->value,
                'category' => ParameterCategory::Counter,
                'required' => true,
                'is_critical' => true,
                'validation_rules' => ['min' => 0, 'category' => 'counter'],
                'mutation_expression' => $defaultMutations['TotalEnergy'],
                'sequence' => 1,
            ],
            [
                'key' => 'PhaseAVoltage',
                'label' => 'Phase A Voltage',
                'json_path' => '$.PhaseAVoltage',
                'type' => ParameterDataType::Decimal,
                'unit' => MetricUnit::Volts->value,
                'required' => true,
                'is_critical' => true,
                'validation_rules' => ['min' => 1800, 'max' => 2800, 'category' => 'static'],
                'mutation_expression' => $defaultMutations['PhaseAVoltage'],
                'sequence' => 2,
            ],
            [
                'key' => 'PhaseBVoltage',
                'label' => 'Phase B Voltage',
                'json_path' => '$.PhaseBVoltage',
                'type' => ParameterDataType::Decimal,
                'unit' => MetricUnit::Volts->value,
                'required' => true,
                'is_critical' => true,
                'validation_rules' => ['min' => 1800, 'max' => 2800, 'category' => 'static'],
                'mutation_expression' => $defaultMutations['PhaseBVoltage'],
                'sequence' => 3,
            ],
            [
                'key' => 'PhaseCVoltage',
                'label' => 'Phase C Voltage',
                'json_path' => '$.PhaseCVoltage',
                'type' => ParameterDataType::Decimal,
                'unit' => MetricUnit::Volts->value,
                'required' => true,
                'is_critical' => true,
                'validation_rules' => ['min' => 1800, 'max' => 2800, 'category' => 'static'],
                'mutation_expression' => $defaultMutations['PhaseCVoltage'],
                'sequence' => 4,
            ],
            [
                'key' => 'PhaseACurrent',
                'label' => 'Phase A Current',
                'json_path' => '$.PhaseACurrent',
                'type' => ParameterDataType::Decimal,
                'unit' => MetricUnit::Amperes->value,
                'required' => true,
                'validation_rules' => ['min' => 0, 'max' => 12000, 'category' => 'static'],
                'mutation_expression' => $defaultMutations['PhaseACurrent'],
                'sequence' => 5,
            ],
            [
                'key' => 'PhaseBCurrent',
                'label' => 'Phase B Current',
                'json_path' => '$.PhaseBCurrent',
                'type' => ParameterDataType::Decimal,
                'unit' => MetricUnit::Amperes->value,
                'required' => true,
                'validation_rules' => ['min' => 0, 'max' => 12000, 'category' => 'static'],
                'mutation_expression' => $defaultMutations['PhaseBCurrent'],
                'sequence' => 6,
            ],
            [
                'key' => 'PhaseCCurrent',
                'label' => 'Phase C Current',
                'json_path' => '$.PhaseCCurrent',
                'type' => ParameterDataType::Decimal,
                'unit' => MetricUnit::Amperes->value,
                'required' => true,
                'validation_rules' => ['min' => 0, 'max' => 12000, 'category' => 'static'],
                'mutation_expression' => $defaultMutations['PhaseCCurrent'],
                'sequence' => 7,
            ],
            [
                'key' => 'totalPowerFactor',
                'label' => 'Total Power Factor',
                'json_path' => '$.totalPowerFactor',
                'type' => ParameterDataType::Decimal,
                'required' => false,
                'validation_rules' => ['min' => 0, 'max' => 1],
                'sequence' => 8,
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $inventory
     * @return array<string, array{signature: string, variant: string, derived_parameters: array<int, array<string, mixed>>, is_compressor: bool}>
     */
    private function schemaConfigurationsBySignature(array $inventory): array
    {
        $schemas = [];

        foreach ($inventory as $deviceConfig) {
            $signature = $this->schemaSignatureFor($deviceConfig);

            if (array_key_exists($signature, $schemas)) {
                continue;
            }

            $isCompressor = $this->isCompressorDevice($deviceConfig);

            $schemas[$signature] = [
                'signature' => $signature,
                'variant' => $isCompressor ? 'teejay-ac-energy-compressor' : 'teejay-ac-energy-standard',
                'derived_parameters' => $isCompressor ? [$this->compressorStatusDerivedParameter()] : [],
                'is_compressor' => $isCompressor,
            ];
        }

        uasort($schemas, function (array $left, array $right): int {
            if ($left['is_compressor'] !== $right['is_compressor']) {
                return $left['is_compressor'] ? 1 : -1;
            }

            return $left['variant'] <=> $right['variant'];
        });

        return $schemas;
    }

    /**
     * @param  array<string, mixed>  $deviceConfig
     */
    private function schemaSignatureFor(array $deviceConfig): string
    {
        return md5(json_encode([
            'is_compressor' => $this->isCompressorDevice($deviceConfig),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function defaultMutationExpressions(): array
    {
        return [
            'TotalEnergy' => ['/' => [['var' => 'val'], 1000]],
            'PhaseAVoltage' => ['/' => [['var' => 'val'], 10]],
            'PhaseBVoltage' => ['/' => [['var' => 'val'], 10]],
            'PhaseCVoltage' => ['/' => [['var' => 'val'], 10]],
            'PhaseACurrent' => ['/' => [['var' => 'val'], 100]],
            'PhaseBCurrent' => ['/' => [['var' => 'val'], 100]],
            'PhaseCCurrent' => ['/' => [['var' => 'val'], 100]],
        ];
    }

    /**
     * @param  array<string, mixed>  $deviceConfig
     */
    private function isCompressorDevice(array $deviceConfig): bool
    {
        $name = $deviceConfig['name'] ?? null;

        return is_string($name) && str_contains(strtolower($name), 'compressor');
    }

    /**
     * @return array<string, mixed>
     */
    private function compressorStatusDerivedParameter(): array
    {
        return [
            'key' => 'status',
            'label' => 'Status',
            'data_type' => ParameterDataType::Integer,
            'expression' => [
                'if' => [
                    ['>' => [['var' => 'PhaseACurrent'], self::COMPRESSOR_STATUS_THRESHOLD]],
                    1,
                    0,
                ],
            ],
            'dependencies' => ['PhaseACurrent'],
            'json_path' => '$.status',
        ];
    }
}
