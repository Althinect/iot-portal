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

class MiracleDomeMigrationSeeder extends Seeder
{
    public const ORGANIZATION_SLUG = 'miracle-dome';

    private const HUB_DEVICE_TYPE_KEY = 'legacy_hub';

    private const HUB_DEVICE_TYPE_NAME = 'Legacy Hub';

    private const HUB_BASE_TOPIC = 'devices/legacy-hub';

    private const HUB_SCHEMA_NAME = 'Legacy Hub Presence';

    private const ENERGY_DEVICE_TYPE_KEY = 'energy_meter';

    private const ENERGY_DEVICE_TYPE_NAME = 'Energy Meter';

    private const ENERGY_BASE_TOPIC = 'energy';

    private const ENERGY_SCHEMA_NAME = 'Energy Meter Contract';

    private const ENERGY_SCHEMA_VERSION = 1;

    private const ENERGY_SCHEMA_VARIANT = 'ac_energy_mate_calibrated';

    /**
     * @var array<int, array{imei: string, name: string}>
     */
    private const HUBS = [
        [
            'imei' => '869244041759261',
            'name' => '869244041759261',
        ],
        [
            'imei' => '869244041759402',
            'name' => '869244041759402',
        ],
    ];

    /**
     * @var array<int, array{
     *     hub_imei: string,
     *     label: string,
     *     legacy_device_uid: string,
     *     peripheral_type_hex: string,
     *     metadata: array{msisdn: ?string, subNumber: ?string, accountNumber: ?string}
     * }>
     */
    private const ENERGY_DEVICES = [
        [
            'hub_imei' => '869244041759261',
            'label' => 'Video Room 2 Energy Meter',
            'legacy_device_uid' => '869244041759261-21',
            'peripheral_type_hex' => '21',
            'metadata' => ['msisdn' => '742475694', 'subNumber' => null, 'accountNumber' => null],
        ],
        [
            'hub_imei' => '869244041759402',
            'label' => 'Server Room 2 Energy meter',
            'legacy_device_uid' => '869244041759402-21',
            'peripheral_type_hex' => '21',
            'metadata' => ['msisdn' => '742475872', 'subNumber' => null, 'accountNumber' => null],
        ],
        [
            'hub_imei' => '869244041759402',
            'label' => 'BTS Energy meter',
            'legacy_device_uid' => '869244041759402-22',
            'peripheral_type_hex' => '22',
            'metadata' => ['msisdn' => '742475872', 'subNumber' => null, 'accountNumber' => null],
        ],
    ];

    /**
     * @var array<int, array{
     *     key: string,
     *     label: string,
     *     json_path: string,
     *     unit?: string,
     *     source_io_number: int,
     *     divisor?: int,
     *     required?: bool,
     *     category?: ParameterCategory,
     *     validation_rules?: array<string, int|float|string>,
     *     sequence: int
     * }>
     */
    private const ENERGY_PARAMETERS = [
        [
            'key' => 'TotalEnergy',
            'label' => 'Total Energy',
            'json_path' => 'TotalEnergy',
            'unit' => MetricUnit::KilowattHours->value,
            'source_io_number' => 7,
            'divisor' => 1000,
            'category' => ParameterCategory::Counter,
            'validation_rules' => ['min' => 0, 'category' => 'counter'],
            'sequence' => 1,
        ],
        [
            'key' => 'PhaseAVoltage',
            'label' => 'Phase A Voltage',
            'json_path' => 'PhaseAVoltage',
            'unit' => MetricUnit::Volts->value,
            'source_io_number' => 1,
            'divisor' => 10,
            'validation_rules' => ['min' => 1800, 'max' => 2800, 'category' => 'static'],
            'sequence' => 2,
        ],
        [
            'key' => 'PhaseBVoltage',
            'label' => 'Phase B Voltage',
            'json_path' => 'PhaseBVoltage',
            'unit' => MetricUnit::Volts->value,
            'source_io_number' => 2,
            'divisor' => 10,
            'validation_rules' => ['min' => 1800, 'max' => 2800, 'category' => 'static'],
            'sequence' => 3,
        ],
        [
            'key' => 'PhaseCVoltage',
            'label' => 'Phase C Voltage',
            'json_path' => 'PhaseCVoltage',
            'unit' => MetricUnit::Volts->value,
            'source_io_number' => 3,
            'divisor' => 10,
            'validation_rules' => ['min' => 1800, 'max' => 2800, 'category' => 'static'],
            'sequence' => 4,
        ],
        [
            'key' => 'PhaseACurrent',
            'label' => 'Phase A Current',
            'json_path' => 'PhaseACurrent',
            'unit' => MetricUnit::Amperes->value,
            'source_io_number' => 4,
            'divisor' => 100,
            'validation_rules' => ['min' => 0, 'max' => 12000, 'category' => 'static'],
            'sequence' => 5,
        ],
        [
            'key' => 'PhaseBCurrent',
            'label' => 'Phase B Current',
            'json_path' => 'PhaseBCurrent',
            'unit' => MetricUnit::Amperes->value,
            'source_io_number' => 5,
            'divisor' => 100,
            'validation_rules' => ['min' => 0, 'max' => 12000, 'category' => 'static'],
            'sequence' => 6,
        ],
        [
            'key' => 'PhaseCCurrent',
            'label' => 'Phase C Current',
            'json_path' => 'PhaseCCurrent',
            'unit' => MetricUnit::Amperes->value,
            'source_io_number' => 6,
            'divisor' => 100,
            'validation_rules' => ['min' => 0, 'max' => 12000, 'category' => 'static'],
            'sequence' => 7,
        ],
        [
            'key' => 'totalPowerFactor',
            'label' => 'Total Power Factor',
            'json_path' => 'totalPowerFactor',
            'source_io_number' => 8,
            'required' => false,
            'validation_rules' => ['min' => 0, 'max' => 1],
            'sequence' => 8,
        ],
    ];

    public function run(): void
    {
        $organization = Organization::withTrashed()->updateOrCreate(
            ['slug' => self::ORGANIZATION_SLUG],
            [
                'name' => 'Miracle Dome',
                'deleted_at' => null,
            ],
        );

        $hubSchemaVersion = $this->upsertHubSchemaVersion();
        $energySchemaVersion = $this->upsertEnergySchemaVersion();

        $energyTopic = $energySchemaVersion->topics()->where('key', 'telemetry')->first();

        if (! $energyTopic instanceof SchemaVersionTopic) {
            throw new \RuntimeException('Miracle Dome energy telemetry topic could not be resolved.');
        }

        /** @var array<string, ParameterDefinition> $parametersByKey */
        $parametersByKey = $energyTopic->parameters()
            ->orderBy('sequence')
            ->get()
            ->keyBy('key')
            ->all();

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

        foreach (self::ENERGY_DEVICES as $deviceConfig) {
            $parentDevice = $hubs[$deviceConfig['hub_imei']] ?? null;

            if (! $parentDevice instanceof Device) {
                continue;
            }

            $device = $this->upsertChildDevice(
                organization: $organization,
                parentDevice: $parentDevice,
                schemaVersion: $energySchemaVersion,
                externalId: $deviceConfig['legacy_device_uid'],
                name: $deviceConfig['label'],
                metadata: [
                    'migration_origin' => self::ORGANIZATION_SLUG,
                    'migration_role' => 'physical_device',
                    'source_adapter' => 'imoni',
                    'schema_variant' => self::ENERGY_SCHEMA_VARIANT,
                    'legacy_device_uid' => $deviceConfig['legacy_device_uid'],
                    'legacy_hub_imei' => $deviceConfig['hub_imei'],
                    'legacy_peripheral_type_hex' => strtoupper($deviceConfig['peripheral_type_hex']),
                    'legacy_metadata' => $deviceConfig['metadata'],
                    'legacy_parameter_map' => $this->legacyEnergyParameterMap($deviceConfig['peripheral_type_hex']),
                    'legacy_calibrations' => $this->legacyEnergyCalibrations(),
                ],
            );

            $expectedParameterIds = [];

            foreach (self::ENERGY_PARAMETERS as $parameterConfig) {
                $parameter = $parametersByKey[$parameterConfig['key']] ?? null;

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
                        'source_topic' => $this->sourceTopicFor(
                            hubImei: $deviceConfig['hub_imei'],
                            peripheralTypeHex: $deviceConfig['peripheral_type_hex'],
                        ),
                        'source_json_path' => '$.io_'.$parameterConfig['source_io_number'].'_value',
                        'source_adapter' => 'imoni',
                        'sequence' => 0,
                        'is_active' => true,
                        'metadata' => [
                            'migration_origin' => self::ORGANIZATION_SLUG,
                            'legacy_device_uid' => $deviceConfig['legacy_device_uid'],
                            'legacy_source_path' => $this->legacyEnergyParameterMap($deviceConfig['peripheral_type_hex'])[$parameterConfig['key']] ?? null,
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
    }

    private function upsertHubSchemaVersion(): DeviceSchemaVersion
    {
        return $this->upsertSchemaVersion(
            deviceTypeKey: self::HUB_DEVICE_TYPE_KEY,
            deviceTypeName: self::HUB_DEVICE_TYPE_NAME,
            baseTopic: self::HUB_BASE_TOPIC,
            schemaName: self::HUB_SCHEMA_NAME,
            topicLabel: 'Heartbeat',
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

    private function upsertEnergySchemaVersion(): DeviceSchemaVersion
    {
        $parameters = array_map(
            fn (array $parameter): array => [
                'key' => $parameter['key'],
                'label' => $parameter['label'],
                'json_path' => $parameter['json_path'],
                'type' => ParameterDataType::Decimal,
                'unit' => $parameter['unit'] ?? null,
                'required' => $parameter['required'] ?? true,
                'is_critical' => ($parameter['required'] ?? true) && $parameter['key'] !== 'totalPowerFactor',
                'category' => $parameter['category'] ?? ParameterCategory::Measurement,
                'validation_rules' => $parameter['validation_rules'] ?? null,
                'mutation_expression' => isset($parameter['divisor']) && $parameter['divisor'] !== 1
                    ? [
                        '/' => [
                            ['var' => 'val'],
                            $parameter['divisor'],
                        ],
                    ]
                    : null,
                'sequence' => $parameter['sequence'],
            ],
            self::ENERGY_PARAMETERS,
        );

        return $this->upsertSchemaVersion(
            deviceTypeKey: self::ENERGY_DEVICE_TYPE_KEY,
            deviceTypeName: self::ENERGY_DEVICE_TYPE_NAME,
            baseTopic: self::ENERGY_BASE_TOPIC,
            schemaName: self::ENERGY_SCHEMA_NAME,
            topicLabel: 'Telemetry',
            parameters: $parameters,
            version: self::ENERGY_SCHEMA_VERSION,
            status: 'draft',
            notes: 'Calibrated legacy AC Energy Mate contract recovered from Miracle Dome.',
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $parameters
     */
    private function upsertSchemaVersion(
        string $deviceTypeKey,
        string $deviceTypeName,
        string $baseTopic,
        string $schemaName,
        string $topicLabel,
        array $parameters,
        int $version = 1,
        string $status = 'active',
        string $notes = 'Miracle Dome migration onboarding schema.',
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
                'label' => $topicLabel,
                'direction' => TopicDirection::Publish,
                'purpose' => TopicPurpose::Telemetry,
                'suffix' => 'telemetry',
                'description' => 'Miracle Dome migration onboarding topic.',
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
     * @return array<string, string>
     */
    private function legacyEnergyParameterMap(string $peripheralTypeHex): array
    {
        $peripheralName = 'AC_energyMate'.(string) (hexdec($peripheralTypeHex) - 0x20);

        return [
            'TotalEnergy' => 'peripheralDataArr.'.$peripheralName.'.7.3',
            'PhaseACurrent' => 'peripheralDataArr.'.$peripheralName.'.4.3',
            'PhaseAVoltage' => 'peripheralDataArr.'.$peripheralName.'.1.3',
            'PhaseBCurrent' => 'peripheralDataArr.'.$peripheralName.'.5.3',
            'PhaseBVoltage' => 'peripheralDataArr.'.$peripheralName.'.2.3',
            'PhaseCCurrent' => 'peripheralDataArr.'.$peripheralName.'.6.3',
            'PhaseCVoltage' => 'peripheralDataArr.'.$peripheralName.'.3.3',
            'totalPowerFactor' => 'peripheralDataArr.'.$peripheralName.'.8.3',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function legacyEnergyCalibrations(): array
    {
        return [
            'TotalEnergy' => 'TotalEnergy/1000',
            'PhaseACurrent' => 'PhaseACurrent/100',
            'PhaseAVoltage' => 'PhaseAVoltage/10',
            'PhaseBCurrent' => 'PhaseBCurrent/100',
            'PhaseBVoltage' => 'PhaseBVoltage/10',
            'PhaseCCurrent' => 'PhaseCCurrent/100',
            'PhaseCVoltage' => 'PhaseCVoltage/10',
        ];
    }
}
