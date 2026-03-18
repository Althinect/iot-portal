<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\DataIngestion\Models\DeviceSignalBinding;
use App\Domain\DeviceManagement\Enums\MqttSecurityMode;
use App\Domain\DeviceManagement\Enums\ProtocolType;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Models\DeviceType;
use App\Domain\DeviceManagement\ValueObjects\Protocol\MqttProtocolConfig;
use App\Domain\DeviceSchema\Enums\MetricUnit;
use App\Domain\DeviceSchema\Enums\ParameterCategory;
use App\Domain\DeviceSchema\Enums\ParameterDataType;
use App\Domain\DeviceSchema\Enums\TopicDirection;
use App\Domain\DeviceSchema\Enums\TopicPurpose;
use App\Domain\DeviceSchema\Models\DeviceSchema;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Domain\DeviceSchema\Models\ParameterDefinition;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Domain\Shared\Models\Organization;
use Illuminate\Database\Seeder;

class TextripMigrationSeeder extends Seeder
{
    public const ORGANIZATION_SLUG = 'textrip';

    private const HUB_DEVICE_TYPE_KEY = 'legacy_hub';

    private const HUB_DEVICE_TYPE_NAME = 'Legacy Hub';

    private const HUB_BASE_TOPIC = 'devices/legacy-hub';

    private const HUB_SCHEMA_NAME = 'Legacy Hub Presence';

    private const AC_DEVICE_TYPE_KEY = 'energy_meter';

    private const AC_DEVICE_TYPE_NAME = 'Energy Meter';

    private const AC_BASE_TOPIC = 'energy';

    private const AC_SCHEMA_NAME = 'Energy Meter Contract';

    private const AC_STANDARD_SCHEMA_VERSION = 3;

    private const AC_VOLTAGE_CALIBRATION_SCHEMA_VERSION = 4;

    private const MODBUS_DEVICE_TYPE_KEY = 'tank_level_sensor';

    private const MODBUS_DEVICE_TYPE_NAME = 'Tank Level Sensor';

    private const MODBUS_BASE_TOPIC = 'storage';

    private const MODBUS_SCHEMA_NAME = 'Tank Level Sensor Contract';

    private const MODBUS_STANDARD_SCHEMA_VERSION = 1;

    private const MODBUS_DIESEL_3000_SCHEMA_VERSION = 2;

    private const MODBUS_DIESEL_10000_SCHEMA_VERSION = 3;

    private const AC_VARIANT_STANDARD = 'ac_standard';

    private const AC_VARIANT_VOLTAGE_ALIAS = 'ac_voltage_alias';

    private const MODBUS_VARIANT_STANDARD = 'modbus_standard';

    private const MODBUS_VARIANT_DIESEL_3000 = 'modbus_diesel_3000';

    private const MODBUS_VARIANT_DIESEL_10000 = 'modbus_diesel_10000';

    /**
     * @var array<int, array{imei: string, name: string}>
     */
    private const HUBS = [
        ['imei' => '869244041759394', 'name' => 'Textrip Main'],
        ['imei' => '869604063839871', 'name' => 'Elasto Fire Pump Water Storage Pond'],
        ['imei' => '869604063842719', 'name' => 'Textrip Fire Pump water Storage'],
        ['imei' => '869604063866064', 'name' => '3000L Diesel tank'],
        ['imei' => '869604063867138', 'name' => 'Drinking Water Sump'],
        ['imei' => '869604063867195', 'name' => 'Elasto Main'],
        ['imei' => '869604063872807', 'name' => 'Roller Section Water Tank'],
        ['imei' => '869604063874100', 'name' => 'Gen 01 300KVA'],
    ];

    /**
     * @var array<int, array{
     *     external_id: string,
     *     hub_imei: string,
     *     name: string,
     *     peripheral_type_hex: string,
     *     schema_variant: string,
     *     metadata: array{msisdn: ?string, subNumber: ?string, accountNumber: ?string}
     * }>
     */
    private const AC_DEVICES = [
        [
            'external_id' => '869244041759394-21',
            'hub_imei' => '869244041759394',
            'name' => 'SES section',
            'peripheral_type_hex' => '21',
            'schema_variant' => self::AC_VARIANT_STANDARD,
            'metadata' => ['msisdn' => '742490267', 'subNumber' => '0994880018', 'accountNumber' => '75864440'],
        ],
        [
            'external_id' => '869244041759394-22',
            'hub_imei' => '869244041759394',
            'name' => 'Oven section',
            'peripheral_type_hex' => '22',
            'schema_variant' => self::AC_VARIANT_STANDARD,
            'metadata' => ['msisdn' => '742490267', 'subNumber' => '0994880018', 'accountNumber' => '75864440'],
        ],
        [
            'external_id' => '869244041759394-23',
            'hub_imei' => '869244041759394',
            'name' => 'Compound section',
            'peripheral_type_hex' => '23',
            'schema_variant' => self::AC_VARIANT_STANDARD,
            'metadata' => ['msisdn' => '742490267', 'subNumber' => '0994880018', 'accountNumber' => '75864440'],
        ],
        [
            'external_id' => '869244041759394-24',
            'hub_imei' => '869244041759394',
            'name' => 'Delan section',
            'peripheral_type_hex' => '24',
            'schema_variant' => self::AC_VARIANT_STANDARD,
            'metadata' => ['msisdn' => '742490267', 'subNumber' => '0994880018', 'accountNumber' => '75864440'],
        ],
        [
            'external_id' => '869244041759394-25',
            'hub_imei' => '869244041759394',
            'name' => 'Textrip section',
            'peripheral_type_hex' => '25',
            'schema_variant' => self::AC_VARIANT_STANDARD,
            'metadata' => ['msisdn' => '742490267', 'subNumber' => '0994880018', 'accountNumber' => '75864440'],
        ],
        [
            'external_id' => '869244041759394-26',
            'hub_imei' => '869244041759394',
            'name' => 'Dipping section',
            'peripheral_type_hex' => '26',
            'schema_variant' => self::AC_VARIANT_STANDARD,
            'metadata' => ['msisdn' => '742490267', 'subNumber' => '0994880018', 'accountNumber' => '75864440'],
        ],
        [
            'external_id' => '869244041759394-27',
            'hub_imei' => '869244041759394',
            'name' => 'Textrip Main',
            'peripheral_type_hex' => '27',
            'schema_variant' => self::AC_VARIANT_VOLTAGE_ALIAS,
            'metadata' => ['msisdn' => '742490267', 'subNumber' => '0994880018', 'accountNumber' => '75864440'],
        ],
        [
            'external_id' => '869604063867195-21',
            'hub_imei' => '869604063867195',
            'name' => 'Gen 02 160kVA',
            'peripheral_type_hex' => '21',
            'schema_variant' => self::AC_VARIANT_STANDARD,
            'metadata' => ['msisdn' => '742491219', 'subNumber' => '0994880018', 'accountNumber' => '75864440'],
        ],
        [
            'external_id' => '869604063867195-22',
            'hub_imei' => '869604063867195',
            'name' => 'Elasto Main',
            'peripheral_type_hex' => '22',
            'schema_variant' => self::AC_VARIANT_STANDARD,
            'metadata' => ['msisdn' => '742491219', 'subNumber' => '0994880018', 'accountNumber' => '75864440'],
        ],
        [
            'external_id' => '869604063874100-21',
            'hub_imei' => '869604063874100',
            'name' => 'CEB Main',
            'peripheral_type_hex' => '21',
            'schema_variant' => self::AC_VARIANT_STANDARD,
            'metadata' => ['msisdn' => '742497036', 'subNumber' => '0994880018', 'accountNumber' => '75864440'],
        ],
        [
            'external_id' => '869604063874100-22',
            'hub_imei' => '869604063874100',
            'name' => 'Gen 01 300KVA',
            'peripheral_type_hex' => '22',
            'schema_variant' => self::AC_VARIANT_STANDARD,
            'metadata' => ['msisdn' => '742497036', 'subNumber' => '0994880018', 'accountNumber' => '75864440'],
        ],
    ];

    /**
     * @var array<int, array{
     *     external_id: string,
     *     hub_imei: string,
     *     name: string,
     *     peripheral_type_hex: string,
     *     schema_variant: string,
     *     metadata: array{msisdn: ?string, subNumber: ?string, accountNumber: ?string}
     * }>
     */
    private const MODBUS_DEVICES = [
        [
            'external_id' => '869604063839871-51',
            'hub_imei' => '869604063839871',
            'name' => 'Elasto Fire Pump',
            'peripheral_type_hex' => '51',
            'schema_variant' => self::MODBUS_VARIANT_STANDARD,
            'metadata' => ['msisdn' => '742491283', 'subNumber' => null, 'accountNumber' => null],
        ],
        [
            'external_id' => '869604063842719-51',
            'hub_imei' => '869604063842719',
            'name' => 'Textrip Fire Pump',
            'peripheral_type_hex' => '51',
            'schema_variant' => self::MODBUS_VARIANT_STANDARD,
            'metadata' => ['msisdn' => null, 'subNumber' => null, 'accountNumber' => null],
        ],
        [
            'external_id' => '869604063866064-51',
            'hub_imei' => '869604063866064',
            'name' => '3000L Diesel tank',
            'peripheral_type_hex' => '51',
            'schema_variant' => self::MODBUS_VARIANT_DIESEL_3000,
            'metadata' => ['msisdn' => '742491856', 'subNumber' => null, 'accountNumber' => null],
        ],
        [
            'external_id' => '869604063866064-52',
            'hub_imei' => '869604063866064',
            'name' => '10000L Diesel tank',
            'peripheral_type_hex' => '52',
            'schema_variant' => self::MODBUS_VARIANT_DIESEL_10000,
            'metadata' => ['msisdn' => '742491856', 'subNumber' => null, 'accountNumber' => null],
        ],
        [
            'external_id' => '869604063867138-51',
            'hub_imei' => '869604063867138',
            'name' => 'Drinking Water Sump',
            'peripheral_type_hex' => '51',
            'schema_variant' => self::MODBUS_VARIANT_STANDARD,
            'metadata' => ['msisdn' => '742490248', 'subNumber' => null, 'accountNumber' => null],
        ],
        [
            'external_id' => '869604063872807-51',
            'hub_imei' => '869604063872807',
            'name' => 'Main Water Tank',
            'peripheral_type_hex' => '51',
            'schema_variant' => self::MODBUS_VARIANT_STANDARD,
            'metadata' => ['msisdn' => '742491423', 'subNumber' => null, 'accountNumber' => null],
        ],
        [
            'external_id' => '869604063872807-52',
            'hub_imei' => '869604063872807',
            'name' => 'Roller Section Water Tank',
            'peripheral_type_hex' => '52',
            'schema_variant' => self::MODBUS_VARIANT_STANDARD,
            'metadata' => ['msisdn' => null, 'subNumber' => null, 'accountNumber' => null],
        ],
    ];

    /**
     * @var array<int, array{
     *     key: string,
     *     label: string,
     *     source_json_path: string,
     *     unit?: string,
     *     category?: ParameterCategory,
     *     validation_rules?: array<string, int|float|string>,
     *     mutation_expression?: array<string, mixed>|null,
     *     voltage_alias_mutation_expression?: array<string, mixed>|null
     * }>
     */
    private const AC_PARAMETER_BINDINGS = [
        [
            'key' => 'v1',
            'label' => 'Voltage V1',
            'source_json_path' => '$.io_1_value',
            'unit' => MetricUnit::Volts->value,
            'validation_rules' => ['min' => 1800, 'max' => 2800, 'category' => 'static'],
            'mutation_expression' => ['/' => [['var' => 'val'], 10]],
        ],
        [
            'key' => 'v2',
            'label' => 'Voltage V2',
            'source_json_path' => '$.io_2_value',
            'unit' => MetricUnit::Volts->value,
            'validation_rules' => ['min' => 1800, 'max' => 2800, 'category' => 'static'],
            'mutation_expression' => ['/' => [['var' => 'val'], 10]],
        ],
        [
            'key' => 'v3',
            'label' => 'Voltage V3',
            'source_json_path' => '$.io_3_value',
            'unit' => MetricUnit::Volts->value,
            'validation_rules' => ['min' => 1800, 'max' => 2800, 'category' => 'static'],
            'mutation_expression' => ['/' => [['var' => 'val'], 10]],
        ],
        [
            'key' => 'i1',
            'label' => 'Current I1',
            'source_json_path' => '$.io_4_value',
            'unit' => MetricUnit::Amperes->value,
            'validation_rules' => ['min' => 0, 'max' => 20000, 'category' => 'static'],
            'mutation_expression' => ['/' => [['var' => 'val'], 100]],
        ],
        [
            'key' => 'i2',
            'label' => 'Current I2',
            'source_json_path' => '$.io_5_value',
            'unit' => MetricUnit::Amperes->value,
            'validation_rules' => ['min' => 0, 'max' => 20000, 'category' => 'static'],
            'mutation_expression' => ['/' => [['var' => 'val'], 100]],
        ],
        [
            'key' => 'i3',
            'label' => 'Current I3',
            'source_json_path' => '$.io_6_value',
            'unit' => MetricUnit::Amperes->value,
            'validation_rules' => ['min' => 0, 'max' => 20000, 'category' => 'static'],
            'mutation_expression' => ['/' => [['var' => 'val'], 100]],
        ],
        [
            'key' => 'e1',
            'label' => 'Energy E1',
            'source_json_path' => '$.io_7_value',
            'unit' => MetricUnit::KilowattHours->value,
            'category' => ParameterCategory::Counter,
            'validation_rules' => ['min' => 0, 'category' => 'counter'],
            'mutation_expression' => ['/' => [['var' => 'val'], 1000]],
        ],
        [
            'key' => 'TotalEnergy',
            'label' => 'Total Energy',
            'source_json_path' => '$.object_values.TotalEnergy.value',
            'unit' => MetricUnit::KilowattHours->value,
            'category' => ParameterCategory::Counter,
            'validation_rules' => ['min' => 0, 'category' => 'counter'],
        ],
        [
            'key' => 'ActivePowerA',
            'label' => 'Active Power A',
            'source_json_path' => '$.object_values.ActivePowerA.value',
            'unit' => MetricUnit::Watts->value,
            'required' => false,
        ],
        [
            'key' => 'ActivePowerB',
            'label' => 'Active Power B',
            'source_json_path' => '$.object_values.ActivePowerB.value',
            'unit' => MetricUnit::Watts->value,
            'required' => false,
        ],
        [
            'key' => 'ActivePowerC',
            'label' => 'Active Power C',
            'source_json_path' => '$.object_values.ActivePowerC.value',
            'unit' => MetricUnit::Watts->value,
            'required' => false,
        ],
        [
            'key' => 'PhaseACurrent',
            'label' => 'Phase A Current',
            'source_json_path' => '$.object_values.PhaseACurrent.value',
            'unit' => MetricUnit::Amperes->value,
            'required' => false,
        ],
        [
            'key' => 'PhaseAVoltage',
            'label' => 'Phase A Voltage',
            'source_json_path' => '$.object_values.PhaseAVoltage.value',
            'unit' => MetricUnit::Volts->value,
            'voltage_alias_mutation_expression' => ['/' => [['var' => 'val'], 10]],
        ],
        [
            'key' => 'PhaseBCurrent',
            'label' => 'Phase B Current',
            'source_json_path' => '$.object_values.PhaseBCurrent.value',
            'unit' => MetricUnit::Amperes->value,
            'required' => false,
        ],
        [
            'key' => 'PhaseBVoltage',
            'label' => 'Phase B Voltage',
            'source_json_path' => '$.object_values.PhaseBVoltage.value',
            'unit' => MetricUnit::Volts->value,
            'voltage_alias_mutation_expression' => ['/' => [['var' => 'val'], 10]],
        ],
        [
            'key' => 'PhaseCCurrent',
            'label' => 'Phase C Current',
            'source_json_path' => '$.object_values.PhaseCCurrent.value',
            'unit' => MetricUnit::Amperes->value,
            'required' => false,
        ],
        [
            'key' => 'PhaseCVoltage',
            'label' => 'Phase C Voltage',
            'source_json_path' => '$.object_values.PhaseCVoltage.value',
            'unit' => MetricUnit::Volts->value,
            'voltage_alias_mutation_expression' => ['/' => [['var' => 'val'], 10]],
        ],
        [
            'key' => 'ReactivePowerA',
            'label' => 'Reactive Power A',
            'source_json_path' => '$.object_values.ReactivePowerA.value',
            'unit' => MetricUnit::Watts->value,
            'required' => false,
        ],
        [
            'key' => 'ReactivePowerB',
            'label' => 'Reactive Power B',
            'source_json_path' => '$.object_values.ReactivePowerB.value',
            'unit' => MetricUnit::Watts->value,
            'required' => false,
        ],
        [
            'key' => 'ReactivePowerC',
            'label' => 'Reactive Power C',
            'source_json_path' => '$.object_values.ReactivePowerC.value',
            'unit' => MetricUnit::Watts->value,
            'required' => false,
        ],
        [
            'key' => 'TotalActivePower',
            'label' => 'Total Active Power',
            'source_json_path' => '$.object_values.TotalActivePower.value',
            'unit' => MetricUnit::Watts->value,
        ],
        [
            'key' => 'totalPowerFactor',
            'label' => 'Total Power Factor',
            'source_json_path' => '$.object_values.totalPowerFactor.value',
        ],
        [
            'key' => 'phaseAPowerFactor',
            'label' => 'Phase A Power Factor',
            'source_json_path' => '$.object_values.phaseAPowerFactor.value',
            'required' => false,
        ],
        [
            'key' => 'phaseBPowerFactor',
            'label' => 'Phase B Power Factor',
            'source_json_path' => '$.object_values.phaseBPowerFactor.value',
            'required' => false,
        ],
        [
            'key' => 'phaseCPowerFactor',
            'label' => 'Phase C Power Factor',
            'source_json_path' => '$.object_values.phaseCPowerFactor.value',
            'required' => false,
        ],
        [
            'key' => 'TotalReactivePower',
            'label' => 'Total Reactive Power',
            'source_json_path' => '$.object_values.TotalReactivePower.value',
            'unit' => MetricUnit::Watts->value,
        ],
    ];

    public function run(): void
    {
        $organization = Organization::withTrashed()->updateOrCreate(
            ['slug' => self::ORGANIZATION_SLUG],
            [
                'name' => 'Textrip',
                'deleted_at' => null,
            ],
        );

        $hubSchemaVersion = $this->upsertHubSchemaVersion();

        $schemaVersions = [
            self::AC_VARIANT_STANDARD => $this->upsertSchemaVersion(
                deviceTypeKey: self::AC_DEVICE_TYPE_KEY,
                deviceTypeName: self::AC_DEVICE_TYPE_NAME,
                baseTopic: self::AC_BASE_TOPIC,
                schemaName: self::AC_SCHEMA_NAME,
                parameters: $this->acParameters(false),
                version: self::AC_STANDARD_SCHEMA_VERSION,
                status: 'draft',
                notes: 'Recovered legacy AC Energy Mate contract with numeric and named object telemetry bindings.',
            ),
            self::AC_VARIANT_VOLTAGE_ALIAS => $this->upsertSchemaVersion(
                deviceTypeKey: self::AC_DEVICE_TYPE_KEY,
                deviceTypeName: self::AC_DEVICE_TYPE_NAME,
                baseTopic: self::AC_BASE_TOPIC,
                schemaName: self::AC_SCHEMA_NAME,
                parameters: $this->acParameters(true),
                version: self::AC_VOLTAGE_CALIBRATION_SCHEMA_VERSION,
                status: 'draft',
                notes: 'Recovered legacy AC Energy Mate contract with calibrated named voltage aliases.',
            ),
            self::MODBUS_VARIANT_STANDARD => $this->upsertSchemaVersion(
                deviceTypeKey: self::MODBUS_DEVICE_TYPE_KEY,
                deviceTypeName: self::MODBUS_DEVICE_TYPE_NAME,
                baseTopic: self::MODBUS_BASE_TOPIC,
                schemaName: self::MODBUS_SCHEMA_NAME,
                parameters: [$this->modbusParameter(null)],
                version: self::MODBUS_STANDARD_SCHEMA_VERSION,
                status: 'active',
                notes: 'Recovered legacy Modbus liquid-level contract without mutation calibration.',
            ),
            self::MODBUS_VARIANT_DIESEL_3000 => $this->upsertSchemaVersion(
                deviceTypeKey: self::MODBUS_DEVICE_TYPE_KEY,
                deviceTypeName: self::MODBUS_DEVICE_TYPE_NAME,
                baseTopic: self::MODBUS_BASE_TOPIC,
                schemaName: self::MODBUS_SCHEMA_NAME,
                parameters: [$this->modbusParameter($this->diesel3000PolynomialExpression())],
                version: self::MODBUS_DIESEL_3000_SCHEMA_VERSION,
                status: 'draft',
                notes: 'Recovered legacy Modbus liquid-level contract with 3000L diesel polynomial calibration.',
            ),
            self::MODBUS_VARIANT_DIESEL_10000 => $this->upsertSchemaVersion(
                deviceTypeKey: self::MODBUS_DEVICE_TYPE_KEY,
                deviceTypeName: self::MODBUS_DEVICE_TYPE_NAME,
                baseTopic: self::MODBUS_BASE_TOPIC,
                schemaName: self::MODBUS_SCHEMA_NAME,
                parameters: [$this->modbusParameter($this->diesel10000PolynomialExpression())],
                version: self::MODBUS_DIESEL_10000_SCHEMA_VERSION,
                status: 'draft',
                notes: 'Recovered legacy Modbus liquid-level contract with 10000L diesel polynomial calibration.',
            ),
        ];

        /** @var array<string, Device> $hubs */
        $hubs = [];

        foreach (self::HUBS as $hubConfig) {
            $hubs[$hubConfig['imei']] = Device::query()->updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'external_id' => $hubConfig['imei'],
                ],
                [
                    'device_type_id' => $hubSchemaVersion->schema->device_type_id,
                    'device_schema_version_id' => $hubSchemaVersion->id,
                    'parent_device_id' => null,
                    'name' => $hubConfig['name'],
                    'metadata' => [
                        'migration_origin' => self::ORGANIZATION_SLUG,
                        'migration_role' => 'hub',
                        'source_adapter' => 'imoni',
                        'imei' => $hubConfig['imei'],
                    ],
                    'is_active' => true,
                    'connection_state' => 'offline',
                    'last_seen_at' => null,
                ],
            );
        }

        foreach (self::AC_DEVICES as $deviceConfig) {
            $parentDevice = $hubs[$deviceConfig['hub_imei']] ?? null;
            $schemaVersion = $schemaVersions[$deviceConfig['schema_variant']] ?? null;

            if (! $parentDevice instanceof Device || ! $schemaVersion instanceof DeviceSchemaVersion) {
                continue;
            }

            $device = $this->upsertChildDevice(
                organization: $organization,
                parentDevice: $parentDevice,
                schemaVersion: $schemaVersion,
                externalId: $deviceConfig['external_id'],
                name: $deviceConfig['name'],
                metadata: [
                    'migration_origin' => self::ORGANIZATION_SLUG,
                    'migration_role' => 'physical_device',
                    'source_adapter' => 'imoni',
                    'schema_variant' => $deviceConfig['schema_variant'],
                    'legacy_hub_imei' => $deviceConfig['hub_imei'],
                    'legacy_peripheral_type_hex' => $deviceConfig['peripheral_type_hex'],
                    'legacy_metadata' => $deviceConfig['metadata'],
                    'legacy_parameter_map' => $this->legacyAcParameterMap($deviceConfig),
                    'legacy_calibrations' => $this->legacyAcCalibrations($deviceConfig['schema_variant']),
                ],
            );

            $this->syncAcBindings($device, $schemaVersion, $deviceConfig);
        }

        foreach (self::MODBUS_DEVICES as $deviceConfig) {
            $parentDevice = $hubs[$deviceConfig['hub_imei']] ?? null;
            $schemaVersion = $schemaVersions[$deviceConfig['schema_variant']] ?? null;

            if (! $parentDevice instanceof Device || ! $schemaVersion instanceof DeviceSchemaVersion) {
                continue;
            }

            $device = $this->upsertChildDevice(
                organization: $organization,
                parentDevice: $parentDevice,
                schemaVersion: $schemaVersion,
                externalId: $deviceConfig['external_id'],
                name: $deviceConfig['name'],
                metadata: [
                    'migration_origin' => self::ORGANIZATION_SLUG,
                    'migration_role' => 'physical_device',
                    'source_adapter' => 'imoni',
                    'schema_variant' => $deviceConfig['schema_variant'],
                    'legacy_hub_imei' => $deviceConfig['hub_imei'],
                    'legacy_peripheral_type_hex' => $deviceConfig['peripheral_type_hex'],
                    'legacy_metadata' => $deviceConfig['metadata'],
                    'legacy_parameter_map' => $this->legacyModbusParameterMap($deviceConfig),
                    'legacy_calibrations' => $this->legacyModbusCalibrations($deviceConfig['schema_variant']),
                ],
            );

            $this->syncModbusBindings($device, $schemaVersion, $deviceConfig);
        }
    }

    private function upsertHubSchemaVersion(): DeviceSchemaVersion
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
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function acParameters(bool $includeVoltageAliasCalibration): array
    {
        $parameters = [];

        foreach (array_values(self::AC_PARAMETER_BINDINGS) as $index => $binding) {
            $parameters[] = [
                'key' => $binding['key'],
                'label' => $binding['label'],
                'json_path' => $binding['key'],
                'type' => ParameterDataType::Decimal,
                'unit' => $binding['unit'] ?? null,
                'required' => $binding['required'] ?? true,
                'is_critical' => in_array($binding['key'], ['TotalEnergy', 'TotalActivePower', 'PhaseAVoltage', 'PhaseBVoltage', 'PhaseCVoltage'], true),
                'category' => $binding['category'] ?? ParameterCategory::Measurement,
                'validation_rules' => $binding['validation_rules'] ?? null,
                'mutation_expression' => $includeVoltageAliasCalibration
                    ? ($binding['voltage_alias_mutation_expression'] ?? $binding['mutation_expression'] ?? null)
                    : ($binding['mutation_expression'] ?? null),
                'sequence' => $index + 1,
            ];
        }

        return $parameters;
    }

    /**
     * @param  array<string, mixed>|null  $mutationExpression
     * @return array<string, mixed>
     */
    private function modbusParameter(?array $mutationExpression): array
    {
        return [
            'key' => 'ioid1',
            'label' => 'Liquid Level',
            'json_path' => 'ioid1',
            'type' => ParameterDataType::Decimal,
            'unit' => MetricUnit::Litres->value,
            'required' => true,
            'is_critical' => true,
            'validation_rules' => ['min' => 0],
            'mutation_expression' => $mutationExpression,
            'sequence' => 1,
        ];
    }

    /**
     * @param  array{
     *     external_id: string,
     *     hub_imei: string,
     *     name: string,
     *     peripheral_type_hex: string,
     *     schema_variant: string,
     *     metadata: array{msisdn: ?string, subNumber: ?string, accountNumber: ?string}
     * }  $deviceConfig
     */
    private function syncAcBindings(Device $device, DeviceSchemaVersion $schemaVersion, array $deviceConfig): void
    {
        $topic = $schemaVersion->topics()->where('key', 'telemetry')->first();

        if (! $topic instanceof SchemaVersionTopic) {
            throw new \RuntimeException('Textrip AC telemetry topic could not be resolved.');
        }

        /** @var array<string, ParameterDefinition> $parametersByKey */
        $parametersByKey = $topic->parameters()
            ->orderBy('sequence')
            ->get()
            ->keyBy('key')
            ->all();

        $expectedParameterIds = [];

        foreach (self::AC_PARAMETER_BINDINGS as $binding) {
            $parameter = $parametersByKey[$binding['key']] ?? null;

            if (! $parameter instanceof ParameterDefinition) {
                continue;
            }

            $expectedParameterIds[] = $parameter->id;

            DeviceSignalBinding::query()->updateOrCreate(
                [
                    'device_id' => $device->id,
                    'parameter_definition_id' => $parameter->id,
                ],
                [
                    'source_topic' => $this->sourceTopicFor($deviceConfig['hub_imei'], $deviceConfig['peripheral_type_hex']),
                    'source_json_path' => $binding['source_json_path'],
                    'source_adapter' => 'imoni',
                    'sequence' => 0,
                    'is_active' => true,
                    'metadata' => [
                        'migration_origin' => self::ORGANIZATION_SLUG,
                        'legacy_external_id' => $deviceConfig['external_id'],
                        'legacy_source_path' => $this->legacyAcParameterMap($deviceConfig)[$binding['key']] ?? null,
                    ],
                ],
            );
        }

        DeviceSignalBinding::query()
            ->where('device_id', $device->id)
            ->where('source_adapter', 'imoni')
            ->whereNotIn('parameter_definition_id', $expectedParameterIds)
            ->delete();
    }

    /**
     * @param  array{
     *     external_id: string,
     *     hub_imei: string,
     *     name: string,
     *     peripheral_type_hex: string,
     *     schema_variant: string,
     *     metadata: array{msisdn: ?string, subNumber: ?string, accountNumber: ?string}
     * }  $deviceConfig
     */
    private function syncModbusBindings(Device $device, DeviceSchemaVersion $schemaVersion, array $deviceConfig): void
    {
        $topic = $schemaVersion->topics()->where('key', 'telemetry')->first();

        if (! $topic instanceof SchemaVersionTopic) {
            throw new \RuntimeException('Textrip Modbus telemetry topic could not be resolved.');
        }

        /** @var ParameterDefinition|null $parameter */
        $parameter = $topic->parameters()->where('key', 'ioid1')->first();

        if (! $parameter instanceof ParameterDefinition) {
            throw new \RuntimeException('Textrip Modbus parameter definition could not be resolved.');
        }

        DeviceSignalBinding::query()->updateOrCreate(
            [
                'device_id' => $device->id,
                'parameter_definition_id' => $parameter->id,
            ],
            [
                'source_topic' => $this->sourceTopicFor($deviceConfig['hub_imei'], $deviceConfig['peripheral_type_hex']),
                'source_json_path' => '$.io_1_value',
                'source_adapter' => 'imoni',
                'sequence' => 0,
                'is_active' => true,
                'metadata' => [
                    'migration_origin' => self::ORGANIZATION_SLUG,
                    'legacy_external_id' => $deviceConfig['external_id'],
                    'legacy_source_path' => $this->legacyModbusParameterMap($deviceConfig)['ioid1'] ?? null,
                ],
            ],
        );

        DeviceSignalBinding::query()
            ->where('device_id', $device->id)
            ->where('source_adapter', 'imoni')
            ->where('parameter_definition_id', '!=', $parameter->id)
            ->delete();
    }

    /**
     * @param  array<int, array<string, mixed>>  $parameters
     */
    private function upsertSchemaVersion(
        string $deviceTypeKey,
        string $deviceTypeName,
        string $baseTopic,
        string $schemaName,
        array $parameters,
        int $version = 1,
        string $status = 'active',
        string $notes = 'Textrip migration onboarding schema.',
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

        if ($schemaVersion->status !== $status || $schemaVersion->notes !== $notes) {
            $schemaVersion->update([
                'status' => $status,
                'notes' => $notes,
            ]);
        }

        $topic = SchemaVersionTopic::query()->updateOrCreate(
            [
                'device_schema_version_id' => $schemaVersion->id,
                'key' => 'telemetry',
            ],
            [
                'label' => 'Telemetry',
                'direction' => TopicDirection::Publish,
                'purpose' => TopicPurpose::Telemetry,
                'suffix' => 'telemetry',
                'description' => 'Textrip migration onboarding topic.',
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

        $parameterKeys = array_map(
            static fn (array $parameter): string => $parameter['key'],
            $parameters,
        );

        ParameterDefinition::query()
            ->where('schema_version_topic_id', $topic->id)
            ->whereNotIn('key', $parameterKeys)
            ->delete();

        return $schemaVersion->fresh(['schema']);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function upsertChildDevice(
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

    private function sourceTopicFor(string $hubImei, string $peripheralTypeHex): string
    {
        return 'migration/source/imoni/'.$hubImei.'/'.strtoupper($peripheralTypeHex).'/telemetry';
    }

    /**
     * @param  array{
     *     external_id: string,
     *     hub_imei: string,
     *     name: string,
     *     peripheral_type_hex: string,
     *     schema_variant: string,
     *     metadata: array{msisdn: ?string, subNumber: ?string, accountNumber: ?string}
     * }  $deviceConfig
     * @return array<string, string>
     */
    private function legacyAcParameterMap(array $deviceConfig): array
    {
        $peripheralName = $this->legacyAcPeripheralName($deviceConfig['peripheral_type_hex']);
        $specialKeys = $deviceConfig['schema_variant'] === self::AC_VARIANT_VOLTAGE_ALIAS
            ? [
                'TotalEnergy' => '3',
                'PhaseAVoltage' => '1',
                'PhaseBVoltage' => '2',
                'PhaseCVoltage' => '3',
            ]
            : [];
        $numericKeys = [
            'v1' => '1',
            'v2' => '2',
            'v3' => '3',
            'i1' => '4',
            'i2' => '5',
            'i3' => '6',
            'e1' => '7',
        ];

        $map = [];

        foreach (self::AC_PARAMETER_BINDINGS as $binding) {
            $objectKey = $specialKeys[$binding['key']] ?? $numericKeys[$binding['key']] ?? $binding['key'];

            $map[$binding['key']] = 'peripheralDataArr.'.$peripheralName.'.'.$objectKey.'.3';
        }

        return $map;
    }

    /**
     * @return array<string, string>
     */
    private function legacyAcCalibrations(string $schemaVariant): array
    {
        $calibrations = [
            'e1' => 'e1/1000',
            'i1' => 'i1/100',
            'i2' => 'i2/100',
            'i3' => 'i3/100',
            'v1' => 'v1/10',
            'v2' => 'v2/10',
            'v3' => 'v3/10',
        ];

        if ($schemaVariant === self::AC_VARIANT_VOLTAGE_ALIAS) {
            $calibrations['PhaseAVoltage'] = 'PhaseAVoltage/10';
            $calibrations['PhaseBVoltage'] = 'PhaseBVoltage/10';
            $calibrations['PhaseCVoltage'] = 'PhaseCVoltage/10';
        }

        return $calibrations;
    }

    /**
     * @param  array{
     *     external_id: string,
     *     hub_imei: string,
     *     name: string,
     *     peripheral_type_hex: string,
     *     schema_variant: string,
     *     metadata: array{msisdn: ?string, subNumber: ?string, accountNumber: ?string}
     * }  $deviceConfig
     * @return array<string, string>
     */
    private function legacyModbusParameterMap(array $deviceConfig): array
    {
        return [
            'ioid1' => 'peripheralDataArr.'.$this->legacyModbusPeripheralName($deviceConfig['peripheral_type_hex']).'.1.3',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function legacyModbusCalibrations(string $schemaVariant): array
    {
        return match ($schemaVariant) {
            self::MODBUS_VARIANT_DIESEL_3000 => [
                'ioid1' => '0.00000000107 * ioid1^6 - 0.0000005068 * ioid1^5 + 0.00009239 * ioid1^4 - 0.009619 * ioid1^3 + 0.6458 * ioid1^2 + 6.154 * ioid1 - 1.723',
            ],
            self::MODBUS_VARIANT_DIESEL_10000 => [
                'ioid1' => '-0.001926 * ioid1^3 + 0.5371 * ioid1^2 + 19.04 * ioid1 - 1.466',
            ],
            default => [],
        };
    }

    private function legacyAcPeripheralName(string $peripheralTypeHex): string
    {
        return 'AC_energyMate'.(string) (hexdec($peripheralTypeHex) - 0x20);
    }

    private function legacyModbusPeripheralName(string $peripheralTypeHex): string
    {
        return 'Modbus'.(string) (hexdec($peripheralTypeHex) - 0x50);
    }

    /**
     * @return array<string, mixed>
     */
    private function diesel3000PolynomialExpression(): array
    {
        return [
            '+' => [
                ['*' => [0.00000000107, $this->valuePower(6)]],
                ['*' => [-0.0000005068, $this->valuePower(5)]],
                ['*' => [0.00009239, $this->valuePower(4)]],
                ['*' => [-0.009619, $this->valuePower(3)]],
                ['*' => [0.6458, $this->valuePower(2)]],
                ['*' => [6.154, ['var' => 'val']]],
                -1.723,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function diesel10000PolynomialExpression(): array
    {
        return [
            '+' => [
                ['*' => [-0.001926, $this->valuePower(3)]],
                ['*' => [0.5371, $this->valuePower(2)]],
                ['*' => [19.04, ['var' => 'val']]],
                -1.466,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function valuePower(int $power): array
    {
        return [
            '*' => array_fill(0, $power, ['var' => 'val']),
        ];
    }
}
