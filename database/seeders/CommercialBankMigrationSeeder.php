<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceSchema\Enums\MetricUnit;
use App\Domain\DeviceSchema\Enums\ParameterCategory;
use App\Domain\DeviceSchema\Enums\ParameterDataType;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Domain\DeviceSchema\Models\ParameterDefinition;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Domain\Shared\Models\Organization;

class CommercialBankMigrationSeeder extends LegacyImoniMigrationSeederSupport
{
    private const ORGANIZATION_SLUG = 'commercial-bank';

    private const ORGANIZATION_NAME = 'Commercial Bank';

    private const ENERGY_DEVICE_TYPE_KEY = 'energy_meter';

    private const ENERGY_DEVICE_TYPE_NAME = 'Energy Meter';

    private const ENERGY_BASE_TOPIC = 'energy';

    private const ENERGY_SCHEMA_NAME = 'Energy Meter Contract';

    private const ENERGY_SCHEMA_VERSION = 20;

    private const CLIMATE_DEVICE_TYPE_KEY = 'legacy_climate_sensor';

    private const CLIMATE_DEVICE_TYPE_NAME = 'Legacy Climate Sensor';

    private const CLIMATE_BASE_TOPIC = 'devices/legacy-climate-sensor';

    private const CLIMATE_SCHEMA_NAME = 'Legacy Climate Sensor Contract';

    private const IMONI_LITE_DEVICE_TYPE_KEY = 'legacy_imoni_lite';

    private const IMONI_LITE_DEVICE_TYPE_NAME = 'Legacy iMoni Lite';

    private const IMONI_LITE_BASE_TOPIC = 'devices/legacy-imoni-lite';

    private const IMONI_LITE_SCHEMA_NAME = 'Legacy iMoni Lite Digital Input Contract';

    /**
     * @var array<int, array{external_id: string, name: string, legacy_device_uid: string, legacy_virtual_device_id: string, legacy_source_organization_id?: int, legacy_source_organization_name?: string}>
     */
    private const HUBS = [
        [
            'external_id' => '869244041748215',
            'name' => 'Panadura ATM',
            'legacy_device_uid' => '869244041748215',
            'legacy_virtual_device_id' => '412c7d04-c574-4d4a-9db7-2dd9f67de7db',
        ],
        [
            'external_id' => '869244041767793',
            'name' => 'Panadura Railway ATM',
            'legacy_device_uid' => '869244041767793',
            'legacy_virtual_device_id' => '0b9fa0b6-ecd5-4493-b6b4-e96e88842ec0',
        ],
        [
            'external_id' => '869244041747894',
            'name' => 'Wellawatta ATM',
            'legacy_device_uid' => '869244041747894',
            'legacy_virtual_device_id' => '0ed06bed-5e66-4981-8a8e-62cd36912c29',
        ],
        [
            'external_id' => '869244041760699',
            'name' => 'Galle Fort Branch',
            'legacy_device_uid' => '869244041760699',
            'legacy_virtual_device_id' => '5e934476-1df9-4630-8d77-c9614ee83da8',
        ],
        [
            'external_id' => '869244041759659',
            'name' => 'GalleFort Energy',
            'legacy_device_uid' => '869244041759659',
            'legacy_virtual_device_id' => '588fe92d-9915-47eb-aee8-33312d5fcf4d',
        ],
        [
            'external_id' => '169244041760699',
            'name' => 'Hub1',
            'legacy_device_uid' => '169244041760699',
            'legacy_virtual_device_id' => 'f1fef0e1-b5bc-47cf-9aaf-0283b5157651',
        ],
        [
            'external_id' => '169244041767793',
            'name' => 'Bank Hub02 (ATM)',
            'legacy_device_uid' => '169244041767793',
            'legacy_virtual_device_id' => '026c51cb-23f0-413b-8b36-639fc4dfdf13',
            'legacy_source_organization_id' => 14,
            'legacy_source_organization_name' => 'Ncinga',
        ],
    ];

    /**
     * @var array<int, array{name: string, external_id: string, hub_imei: string, peripheral_type_hex: string, parameter_map: array<string, string>, metadata?: array<string, string|null>, legacy_virtual_device_id: string}>
     */
    private const CLIMATE_DEVICES = [
        [
            'name' => 'Temp Sensor - Panadura Railway ATM',
            'external_id' => '869244041748215-81',
            'hub_imei' => '869244041748215',
            'peripheral_type_hex' => '81',
            'parameter_map' => [
                'temperature' => 'peripheralDataArr.THsensor1.2.3',
                'humidity' => 'peripheralDataArr.THsensor1.1.3',
            ],
            'metadata' => ['msisdn' => null, 'subNumber' => null, 'accountNumber' => null],
            'legacy_virtual_device_id' => '2071d05b-6883-4968-8dbe-0828ccd1464a',
        ],
        [
            'name' => 'Panadura Railway ATM - T&H',
            'external_id' => '869244041767793-81',
            'hub_imei' => '869244041767793',
            'peripheral_type_hex' => '81',
            'parameter_map' => [
                'temperature' => 'peripheralDataArr.THsensor1.2.3',
                'humidity' => 'peripheralDataArr.THsensor1.3.3',
            ],
            'metadata' => ['msisdn' => '0768193695', 'subNumber' => null, 'accountNumber' => null],
            'legacy_virtual_device_id' => 'c56b5e26-2173-49e9-bb60-8c45fe641a0b',
        ],
        [
            'name' => 'Wellawatta ATM - T & H',
            'external_id' => '869244041747894-81',
            'hub_imei' => '869244041747894',
            'peripheral_type_hex' => '81',
            'parameter_map' => [
                'temperature' => 'peripheralDataArr.THsensor1.2.3',
                'humidity' => 'peripheralDataArr.THsensor1.1.3',
            ],
            'metadata' => ['msisdn' => '768191591', 'subNumber' => null, 'accountNumber' => null],
            'legacy_virtual_device_id' => 'b39556f6-242d-4d95-af43-1912e2bf1b75',
        ],
        [
            'name' => 'GalleFort ATM T&H',
            'external_id' => '869244041760699-81',
            'hub_imei' => '869244041760699',
            'peripheral_type_hex' => '81',
            'parameter_map' => [
                'temperature' => 'peripheralDataArr.THsensor1.2.3',
                'humidity' => 'peripheralDataArr.THsensor1.3.3',
            ],
            'metadata' => ['msisdn' => '742476343', 'subNumber' => '994211928', 'accountNumber' => '75722832'],
            'legacy_virtual_device_id' => '340eb3ae-186b-4e20-a8c4-89a9d8be6fcd',
        ],
        [
            'name' => 'GalleFort Office L1 T&H',
            'external_id' => '869244041760699-82',
            'hub_imei' => '869244041760699',
            'peripheral_type_hex' => '82',
            'parameter_map' => [
                'temperature' => 'peripheralDataArr.THsensor2.2.3',
                'humidity' => 'peripheralDataArr.THsensor2.3.3',
            ],
            'metadata' => ['msisdn' => '742476343', 'subNumber' => '994211928', 'accountNumber' => '75722832'],
            'legacy_virtual_device_id' => '2464460a-0a83-461e-bb42-2b36efc4a64c',
        ],
        [
            'name' => 'GalleFort Office L2 T&H',
            'external_id' => '869244041760699-83',
            'hub_imei' => '869244041760699',
            'peripheral_type_hex' => '83',
            'parameter_map' => [
                'temperature' => 'peripheralDataArr.THsensor3.2.3',
                'humidity' => 'peripheralDataArr.THsensor3.3.3',
            ],
            'metadata' => ['msisdn' => '742476343', 'subNumber' => '994211928', 'accountNumber' => '75722832'],
            'legacy_virtual_device_id' => 'cac7537f-8c0a-443b-9b1f-3643fbe77bbf',
        ],
        [
            'name' => 'Branch Office L3 T&H',
            'external_id' => '169244041760699-81',
            'hub_imei' => '169244041760699',
            'peripheral_type_hex' => '81',
            'parameter_map' => [
                'temperature' => 'peripheralDataArr.THsensor1.2.3',
                'humidity' => 'peripheralDataArr.THsensor1.3.3',
            ],
            'legacy_virtual_device_id' => '73af3b5b-b157-41a7-aaab-faba3f5c6653',
        ],
        [
            'name' => 'Branch Office L1 T&H',
            'external_id' => '169244041760699-82',
            'hub_imei' => '169244041760699',
            'peripheral_type_hex' => '82',
            'parameter_map' => [
                'temperature' => 'peripheralDataArr.THsensor2.2.3',
                'humidity' => 'peripheralDataArr.THsensor2.3.3',
            ],
            'legacy_virtual_device_id' => 'b1c01bf4-8901-4b1a-8604-063fcd0c87f1',
        ],
        [
            'name' => 'Branch Office L2 T&H',
            'external_id' => '169244041760699-83',
            'hub_imei' => '169244041760699',
            'peripheral_type_hex' => '83',
            'parameter_map' => [
                'temperature' => 'peripheralDataArr.THsensor3.2.3',
                'humidity' => 'peripheralDataArr.THsensor3.3.3',
            ],
            'legacy_virtual_device_id' => '92dec887-332e-486b-a55d-161a2dd538e9',
        ],
        [
            'name' => 'ATM - T&H',
            'external_id' => '169244041767793-81',
            'hub_imei' => '169244041767793',
            'peripheral_type_hex' => '81',
            'parameter_map' => [
                'temperature' => 'peripheralDataArr.THsensor1.2.3',
                'humidity' => 'peripheralDataArr.THsensor1.3.3',
            ],
            'legacy_virtual_device_id' => '4baf3f6f-f2b1-4383-94c3-83500acf4ae0',
        ],
    ];

    /**
     * @var array<int, array{name: string, external_id: string, hub_imei: string, peripheral_type_hex: string, parameter_map: array<string, string>, metadata?: array<string, string|null>, legacy_virtual_device_id: string, legacy_source_organization_id?: int, legacy_source_organization_name?: string}>
     */
    private const IMONI_LITE_DEVICES = [
        [
            'name' => 'iMoni - Panadura Railway ATM',
            'external_id' => '869244041748215-00',
            'hub_imei' => '869244041748215',
            'peripheral_type_hex' => '00',
            'parameter_map' => [
                'ioid2' => 'peripheralDataArr.iMoni_LITE.2.3',
                'ioid3' => 'peripheralDataArr.iMoni_LITE.3.3',
                'ioid4' => 'peripheralDataArr.iMoni_LITE.4.3',
                'ioid5' => 'peripheralDataArr.iMoni_LITE.5.3',
                'ioid6' => 'peripheralDataArr.iMoni_LITE.6.3',
            ],
            'metadata' => ['msisdn' => null, 'subNumber' => null, 'accountNumber' => null],
            'legacy_virtual_device_id' => '8f60d25d-1941-4266-af61-9021d0ac81d6',
        ],
        [
            'name' => 'Panadura Railway ATM - iMoni',
            'external_id' => '869244041767793-00',
            'hub_imei' => '869244041767793',
            'peripheral_type_hex' => '00',
            'parameter_map' => [
                'ioid2' => 'peripheralDataArr.iMoni_LITE.2.3',
                'ioid3' => 'peripheralDataArr.iMoni_LITE.3.3',
                'ioid4' => 'peripheralDataArr.iMoni_LITE.4.3',
                'ioid5' => 'peripheralDataArr.iMoni_LITE.5.3',
                'ioid6' => 'peripheralDataArr.iMoni_LITE.6.3',
                'ioid7' => 'peripheralDataArr.iMoni_LITE.7.3',
                'ioid17' => 'peripheralDataArr.iMoni_LITE.17.3',
            ],
            'metadata' => ['msisdn' => '0768193695', 'subNumber' => '994211925', 'accountNumber' => 'ES00032042'],
            'legacy_virtual_device_id' => '4a43a62f-0ec0-4cd5-a671-f00187926669',
        ],
        [
            'name' => 'Wellawatta ATM - iMoni',
            'external_id' => '869244041747894-00',
            'hub_imei' => '869244041747894',
            'peripheral_type_hex' => '00',
            'parameter_map' => [
                'ioid2' => 'peripheralDataArr.iMoni_LITE.2.3',
                'ioid3' => 'peripheralDataArr.iMoni_LITE.3.3',
                'ioid4' => 'peripheralDataArr.iMoni_LITE.4.3',
                'ioid5' => 'peripheralDataArr.iMoni_LITE.5.3',
                'ioid6' => 'peripheralDataArr.iMoni_LITE.6.3',
            ],
            'metadata' => ['msisdn' => '768191591', 'subNumber' => '994211926', 'accountNumber' => 'ES00032042'],
            'legacy_virtual_device_id' => '824f9851-ad66-4997-b027-309af01a3e27',
        ],
        [
            'name' => 'GalleFort ATM iMoni',
            'external_id' => '869244041760699-00',
            'hub_imei' => '869244041760699',
            'peripheral_type_hex' => '00',
            'parameter_map' => [
                'ioid2' => 'peripheralDataArr.iMoni_LITE.2.3',
                'ioid3' => 'peripheralDataArr.iMoni_LITE.3.3',
                'ioid4' => 'peripheralDataArr.iMoni_LITE.4.3',
                'ioid5' => 'peripheralDataArr.iMoni_LITE.5.3',
            ],
            'metadata' => ['msisdn' => '742476343', 'subNumber' => '994211928', 'accountNumber' => '75722832'],
            'legacy_virtual_device_id' => 'd5af491c-fc47-401c-99a4-281177676fa6',
        ],
        [
            'name' => 'Vault Door',
            'external_id' => '169244041760699-00',
            'hub_imei' => '169244041760699',
            'peripheral_type_hex' => '00',
            'parameter_map' => [
                'ioid1' => 'peripheralDataArr.iMoni_LITE.2.3',
                'ioid2' => 'peripheralDataArr.iMoni_LITE.3.3',
                'ioid4' => 'peripheralDataArr.iMoni_LITE.4.3',
                'ioid5' => 'peripheralDataArr.iMoni_LITE.5.3',
            ],
            'legacy_virtual_device_id' => 'fe1dd02c-0dca-4fa9-8303-65e23bb0fc17',
        ],
        [
            'name' => 'ATM - Hub Status',
            'external_id' => '169244041767793-00',
            'hub_imei' => '169244041767793',
            'peripheral_type_hex' => '00',
            'parameter_map' => [
                'ioid2' => 'peripheralDataArr.iMoni_LITE.2.3',
                'ioid3' => 'peripheralDataArr.iMoni_LITE.3.3',
                'ioid4' => 'peripheralDataArr.iMoni_LITE.4.3',
                'ioid5' => 'peripheralDataArr.iMoni_LITE.5.3',
                'ioid6' => 'peripheralDataArr.iMoni_LITE.6.3',
                'ioid7' => 'peripheralDataArr.iMoni_LITE.7.3',
                'ioid17' => 'peripheralDataArr.iMoni_LITE.17.3',
            ],
            'legacy_virtual_device_id' => 'fc1e3290-61b2-4afc-9128-9bd97457f420',
            'legacy_source_organization_id' => 14,
            'legacy_source_organization_name' => 'Ncinga',
        ],
    ];

    /**
     * @var array<int, array{name: string, external_id: string, hub_imei: string, peripheral_type_hex: string, parameter_map: array<string, string>, calibrations: array<string, string>, metadata: array<string, string>, legacy_virtual_device_id: string}>
     */
    private const ENERGY_DEVICES = [
        [
            'name' => 'GalleFort Energy Light DB',
            'external_id' => '869244041759659-21',
            'hub_imei' => '869244041759659',
            'peripheral_type_hex' => '21',
            'parameter_map' => [
                'TotalEnergy' => 'peripheralDataArr.AC_energyMate1.7.3',
                'PhaseACurrent' => 'peripheralDataArr.AC_energyMate1.4.3',
                'PhaseAVoltage' => 'peripheralDataArr.AC_energyMate1.1.3',
                'PhaseBCurrent' => 'peripheralDataArr.AC_energyMate1.5.3',
                'PhaseBVoltage' => 'peripheralDataArr.AC_energyMate1.2.3',
                'PhaseCCurrent' => 'peripheralDataArr.AC_energyMate1.6.3',
                'PhaseCVoltage' => 'peripheralDataArr.AC_energyMate1.3.3',
                'totalPowerFactor' => 'peripheralDataArr.AC_energyMate1.8.3',
            ],
            'calibrations' => [
                'TotalEnergy' => 'TotalEnergy/1000',
                'PhaseACurrent' => 'PhaseACurrent/100',
                'PhaseAVoltage' => 'PhaseAVoltage/10',
                'PhaseBCurrent' => 'PhaseBCurrent/100',
                'PhaseBVoltage' => 'PhaseBVoltage/10',
                'PhaseCCurrent' => 'PhaseCCurrent/100',
                'PhaseCVoltage' => 'PhaseCVoltage/10',
            ],
            'metadata' => ['msisdn' => '742451641', 'subNumber' => '994880007', 'accountNumber' => '75722832'],
            'legacy_virtual_device_id' => 'a6bc488f-460f-4e56-b343-dcd0171c3b24',
        ],
        [
            'name' => 'GalleFort Energy AC DB',
            'external_id' => '869244041759659-22',
            'hub_imei' => '869244041759659',
            'peripheral_type_hex' => '22',
            'parameter_map' => [
                'TotalEnergy' => 'peripheralDataArr.AC_energyMate2.7.3',
                'PhaseACurrent' => 'peripheralDataArr.AC_energyMate2.4.3',
                'PhaseAVoltage' => 'peripheralDataArr.AC_energyMate2.1.3',
                'PhaseBCurrent' => 'peripheralDataArr.AC_energyMate2.5.3',
                'PhaseBVoltage' => 'peripheralDataArr.AC_energyMate2.2.3',
                'PhaseCCurrent' => 'peripheralDataArr.AC_energyMate2.6.3',
                'PhaseCVoltage' => 'peripheralDataArr.AC_energyMate2.3.3',
                'totalPowerFactor' => 'peripheralDataArr.AC_energyMate2.8.3',
            ],
            'calibrations' => [
                'TotalEnergy' => 'TotalEnergy/1000',
                'PhaseACurrent' => 'PhaseACurrent/100',
                'PhaseAVoltage' => 'PhaseAVoltage/10',
                'PhaseBCurrent' => 'PhaseBCurrent/100',
                'PhaseBVoltage' => 'PhaseBVoltage/10',
                'PhaseCCurrent' => 'PhaseCCurrent/100',
                'PhaseCVoltage' => 'PhaseCVoltage/10',
            ],
            'metadata' => ['msisdn' => '742451641', 'subNumber' => '994880007', 'accountNumber' => '75722832'],
            'legacy_virtual_device_id' => '0007e1bc-d4b5-4932-8224-ac50c8b73214',
        ],
    ];

    public function run(): void
    {
        $organization = $this->ensureOrganization();
        $hubs = $this->ensureHubs($organization);
        $hubConfigsByImei = $this->hubConfigsByImei();

        $this->tagSourceOrganizationHubs($hubs, $hubConfigsByImei);

        $this->seedChildDevices(
            organization: $organization,
            hubs: $hubs,
            hubConfigsByImei: $hubConfigsByImei,
            migrationDeviceType: 'TH Sensor',
            schemaVersion: $this->upsertClimateSchemaVersion(),
            devices: self::CLIMATE_DEVICES,
        );

        $this->seedChildDevices(
            organization: $organization,
            hubs: $hubs,
            hubConfigsByImei: $hubConfigsByImei,
            migrationDeviceType: 'IMoni Lite',
            schemaVersion: $this->upsertImoniLiteSchemaVersion(),
            devices: self::IMONI_LITE_DEVICES,
        );

        $this->seedChildDevices(
            organization: $organization,
            hubs: $hubs,
            hubConfigsByImei: $hubConfigsByImei,
            migrationDeviceType: 'AC Energy Mate',
            schemaVersion: $this->upsertEnergySchemaVersion(),
            devices: self::ENERGY_DEVICES,
        );

        $this->cleanupDevices($organization, 'TH Sensor', $this->expectedExternalIdsFor(self::CLIMATE_DEVICES));
        $this->cleanupDevices($organization, 'IMoni Lite', $this->expectedExternalIdsFor(self::IMONI_LITE_DEVICES));
        $this->cleanupDevices($organization, 'AC Energy Mate', $this->expectedExternalIdsFor(self::ENERGY_DEVICES));
        $this->cleanupHubs($organization);
    }

    protected function organizationSlug(): string
    {
        return self::ORGANIZATION_SLUG;
    }

    protected function organizationName(): string
    {
        return self::ORGANIZATION_NAME;
    }

    /**
     * @return array<int, array{external_id: string, name: string, legacy_device_uid: string|null, legacy_virtual_device_id: string|null}>
     */
    protected function hubInventory(): array
    {
        return self::HUBS;
    }

    private function upsertClimateSchemaVersion(): DeviceSchemaVersion
    {
        return $this->upsertSchemaVersion(
            deviceTypeKey: self::CLIMATE_DEVICE_TYPE_KEY,
            deviceTypeName: self::CLIMATE_DEVICE_TYPE_NAME,
            baseTopic: self::CLIMATE_BASE_TOPIC,
            schemaName: self::CLIMATE_SCHEMA_NAME,
            parameters: [
                [
                    'key' => 'temperature',
                    'label' => 'Temperature',
                    'json_path' => '$.temperature',
                    'type' => ParameterDataType::Decimal,
                    'unit' => MetricUnit::Celsius->value,
                    'required' => true,
                    'is_critical' => true,
                    'validation_rules' => ['min' => 0, 'max' => 65535, 'category' => 'static'],
                    'mutation_expression' => $this->legacyImoniTemperatureMutation(),
                    'sequence' => 1,
                ],
                [
                    'key' => 'humidity',
                    'label' => 'Humidity',
                    'json_path' => '$.humidity',
                    'type' => ParameterDataType::Decimal,
                    'unit' => MetricUnit::Percent->value,
                    'required' => false,
                    'is_critical' => false,
                    'validation_rules' => ['min' => 0, 'max' => 1000, 'category' => 'static'],
                    'mutation_expression' => $this->divideBy(10),
                    'sequence' => 2,
                ],
            ],
            notes: 'Commercial Bank legacy T&H sensors recovered from the old iot-demo inventory.',
        );
    }

    private function upsertImoniLiteSchemaVersion(): DeviceSchemaVersion
    {
        return $this->upsertSchemaVersion(
            deviceTypeKey: self::IMONI_LITE_DEVICE_TYPE_KEY,
            deviceTypeName: self::IMONI_LITE_DEVICE_TYPE_NAME,
            baseTopic: self::IMONI_LITE_BASE_TOPIC,
            schemaName: self::IMONI_LITE_SCHEMA_NAME,
            parameters: $this->imoniLiteParameterDefinitions(),
            notes: 'Commercial Bank legacy iMoni Lite digital inputs recovered from the old iot-demo inventory.',
        );
    }

    private function upsertEnergySchemaVersion(): DeviceSchemaVersion
    {
        return $this->upsertSchemaVersion(
            deviceTypeKey: self::ENERGY_DEVICE_TYPE_KEY,
            deviceTypeName: self::ENERGY_DEVICE_TYPE_NAME,
            baseTopic: self::ENERGY_BASE_TOPIC,
            schemaName: self::ENERGY_SCHEMA_NAME,
            parameters: $this->energyParameterDefinitions(),
            version: self::ENERGY_SCHEMA_VERSION,
            status: 'draft',
            notes: 'Commercial Bank legacy AC Energy Mate contract recovered from the old iot-demo inventory.',
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function imoniLiteParameterDefinitions(): array
    {
        return array_map(
            static fn (int $inputNumber, int $index): array => [
                'key' => 'ioid'.$inputNumber,
                'label' => 'Digital Input '.$inputNumber,
                'json_path' => '$.ioid'.$inputNumber,
                'type' => ParameterDataType::Boolean,
                'required' => false,
                'is_critical' => false,
                'category' => ParameterCategory::State,
                'validation_rules' => ['category' => 'state'],
                'sequence' => $index + 1,
            ],
            [1, 2, 3, 4, 5, 6, 7, 17],
            array_keys([1, 2, 3, 4, 5, 6, 7, 17]),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function energyParameterDefinitions(): array
    {
        $defaultMutations = $this->defaultEnergyMutationExpressions();

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
     * @param  array<string, Device>  $hubs
     * @param  array<string, array<string, mixed>>  $hubConfigsByImei
     */
    private function tagSourceOrganizationHubs(array $hubs, array $hubConfigsByImei): void
    {
        foreach ($hubConfigsByImei as $hubImei => $hubConfig) {
            $hub = $hubs[$hubImei] ?? null;

            if (! $hub instanceof Device || ! isset($hubConfig['legacy_source_organization_id'], $hubConfig['legacy_source_organization_name'])) {
                continue;
            }

            $metadata = is_array($hub->metadata) ? $hub->metadata : [];
            $metadata['legacy_source_organization_id'] = $hubConfig['legacy_source_organization_id'];
            $metadata['legacy_source_organization_name'] = $hubConfig['legacy_source_organization_name'];

            $hub->forceFill(['metadata' => $metadata])->save();
        }
    }

    /**
     * @param  array<string, Device>  $hubs
     * @param  array<string, array<string, mixed>>  $hubConfigsByImei
     * @param  array<int, array<string, mixed>>  $devices
     */
    private function seedChildDevices(
        Organization $organization,
        array $hubs,
        array $hubConfigsByImei,
        string $migrationDeviceType,
        DeviceSchemaVersion $schemaVersion,
        array $devices,
    ): void {
        $parametersByKey = $this->parametersByKey($schemaVersion);

        foreach ($devices as $deviceConfig) {
            $hubImei = (string) $deviceConfig['hub_imei'];
            $parentDevice = $hubs[$hubImei] ?? null;

            if (! $parentDevice instanceof Device) {
                continue;
            }

            $device = $this->upsertChildDevice(
                organization: $organization,
                parentDevice: $parentDevice,
                schemaVersion: $schemaVersion,
                externalId: (string) $deviceConfig['external_id'],
                name: (string) $deviceConfig['name'],
                metadata: $this->deviceMetadata($deviceConfig, $migrationDeviceType, $hubConfigsByImei[$hubImei] ?? []),
            );

            $this->syncBindings(
                device: $device,
                hubImei: $hubImei,
                peripheralTypeHex: (string) $deviceConfig['peripheral_type_hex'],
                parametersByKey: $parametersByKey,
                bindingDefinitions: $this->bindingDefinitionsFor($deviceConfig),
                deviceMetadata: ['legacy_device_uid' => (string) $deviceConfig['external_id']],
            );
        }
    }

    /**
     * @return array<string, ParameterDefinition>
     */
    private function parametersByKey(DeviceSchemaVersion $schemaVersion): array
    {
        $topic = $schemaVersion->topics()->where('key', 'telemetry')->first();

        if (! $topic instanceof SchemaVersionTopic) {
            return [];
        }

        /** @var array<string, ParameterDefinition> $parametersByKey */
        $parametersByKey = $topic->parameters()
            ->orderBy('sequence')
            ->get()
            ->keyBy('key')
            ->all();

        return $parametersByKey;
    }

    /**
     * @param  array<string, mixed>  $deviceConfig
     * @return array<string, array<string, mixed>>
     */
    private function bindingDefinitionsFor(array $deviceConfig): array
    {
        $bindings = [];
        $parameterMap = $deviceConfig['parameter_map'] ?? [];

        if (! is_array($parameterMap)) {
            return [];
        }

        $sequence = 1;

        foreach ($parameterMap as $parameterKey => $legacyPath) {
            if (! is_string($parameterKey) || ! is_string($legacyPath)) {
                continue;
            }

            $sourceJsonPath = $this->normalizedSourcePath($legacyPath);

            if ($sourceJsonPath === null) {
                continue;
            }

            $bindings[$parameterKey] = $this->withoutNullValues([
                'source_json_path' => $sourceJsonPath,
                'legacy_source_path' => $legacyPath,
                'mutation_expression' => $this->mutationExpressionForParameter($deviceConfig, $parameterKey),
                'decoder' => $this->decoderFor(
                    hubImei: (string) $deviceConfig['hub_imei'],
                    peripheralTypeHex: (string) $deviceConfig['peripheral_type_hex'],
                    sourceJsonPath: $sourceJsonPath,
                ),
                'sequence' => $sequence,
            ]);

            $sequence++;
        }

        return $bindings;
    }

    /**
     * @param  array<string, mixed>  $deviceConfig
     * @param  array<string, mixed>  $parentHubConfig
     * @return array<string, mixed>
     */
    private function deviceMetadata(array $deviceConfig, string $migrationDeviceType, array $parentHubConfig): array
    {
        return $this->withoutNullValues([
            'migration_origin' => self::ORGANIZATION_SLUG,
            'migration_role' => 'physical_device',
            'migration_device_type' => $migrationDeviceType,
            'source_adapter' => 'imoni',
            'legacy_device_uid' => $deviceConfig['external_id'] ?? null,
            'legacy_virtual_device_id' => $deviceConfig['legacy_virtual_device_id'] ?? null,
            'legacy_hub_imei' => $deviceConfig['hub_imei'] ?? null,
            'legacy_peripheral_type_hex' => $deviceConfig['peripheral_type_hex'] ?? null,
            'legacy_parameter_map' => $deviceConfig['parameter_map'] ?? null,
            'legacy_calibrations' => $this->nonEmptyArray($deviceConfig['calibrations'] ?? []),
            'legacy_conditional_calibrations' => $this->nonEmptyArray($deviceConfig['conditional_calibrations'] ?? []),
            'legacy_metadata' => $this->nonEmptyArray($deviceConfig['metadata'] ?? []),
            'legacy_source_organization_id' => $deviceConfig['legacy_source_organization_id'] ?? null,
            'legacy_source_organization_name' => $deviceConfig['legacy_source_organization_name'] ?? null,
            'legacy_parent_source_organization_id' => $parentHubConfig['legacy_source_organization_id'] ?? null,
            'legacy_parent_source_organization_name' => $parentHubConfig['legacy_source_organization_name'] ?? null,
        ]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function hubConfigsByImei(): array
    {
        $hubsByImei = [];

        foreach (self::HUBS as $hubConfig) {
            $hubsByImei[$hubConfig['external_id']] = $hubConfig;
        }

        return $hubsByImei;
    }

    /**
     * @param  array<int, array<string, mixed>>  $devices
     * @return array<int, string>
     */
    private function expectedExternalIdsFor(array $devices): array
    {
        return array_values(array_map(
            static fn (array $deviceConfig): string => (string) $deviceConfig['external_id'],
            $devices,
        ));
    }

    /**
     * @param  array<string, mixed>  $array
     * @return array<string, mixed>|null
     */
    private function nonEmptyArray(array $array): ?array
    {
        return $array === [] ? null : $array;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function withoutNullValues(array $values): array
    {
        return array_filter($values, static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function defaultEnergyMutationExpressions(): array
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
     * @return array<string, mixed>
     */
    private function divideBy(int $divisor): array
    {
        return [
            '/' => [
                ['var' => 'val'],
                $divisor,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function legacyImoniTemperatureMutation(): array
    {
        return [
            'if' => [
                [
                    '>' => [
                        ['var' => 'val'],
                        32768,
                    ],
                ],
                [
                    '*' => [
                        [
                            '/' => [
                                [
                                    '-' => [
                                        ['var' => 'val'],
                                        32768,
                                    ],
                                ],
                                10,
                            ],
                        ],
                        -1,
                    ],
                ],
                [
                    '/' => [
                        ['var' => 'val'],
                        10,
                    ],
                ],
            ],
        ];
    }
}
