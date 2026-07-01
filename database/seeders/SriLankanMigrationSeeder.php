<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\DataIngestion\Models\DeviceSignalBinding;
use App\Domain\DeviceManagement\Enums\MqttSecurityMode;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\ValueObjects\Protocol\MqttProtocolConfig;
use App\Domain\DeviceProfile\Enums\ChannelDirection;
use App\Domain\DeviceProfile\Enums\ChannelPurpose;
use App\Domain\DeviceProfile\Enums\ChannelTransport;
use App\Domain\DeviceProfile\Enums\MetricUnit;
use App\Domain\DeviceProfile\Enums\ParameterCategory;
use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Enums\Protocol;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\DeviceProfile;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use App\Domain\Shared\Models\Organization;
use Illuminate\Database\Seeder;

class SriLankanMigrationSeeder extends Seeder
{
    public const ORGANIZATION_SLUG = 'srilankan-airlines';

    private const ORGANIZATION_NAME = 'SriLankan Airlines Limited';

    private const HUB_DEVICE_TYPE_KEY = 'legacy_hub';

    private const HUB_DEVICE_TYPE_NAME = 'Legacy Hub';

    private const HUB_BASE_TOPIC = 'devices/legacy-hub';

    private const HUB_SCHEMA_NAME = 'Legacy Hub Presence';

    private const CLIMATE_DEVICE_TYPE_KEY = 'legacy_climate_sensor';

    private const CLIMATE_DEVICE_TYPE_NAME = 'Legacy Climate Sensor';

    private const CLIMATE_BASE_TOPIC = 'devices/legacy-climate-sensor';

    private const CLIMATE_SCHEMA_NAME = 'Legacy Climate Sensor Contract';

    private const IMONI_PERIPHERAL_TYPE_HEX = '00';

    private const EGRAVITY_DEVICE_TYPE_KEY = 'legacy_egravity_sensor';

    private const EGRAVITY_DEVICE_TYPE_NAME = 'Legacy Egravity Sensor';

    private const EGRAVITY_BASE_TOPIC = 'devices/legacy-egravity-sensor';

    private const EGRAVITY_SCHEMA_NAME = 'Legacy Egravity Sensor Contract';

    /**
     * @var array<int, array{
     *     external_id: string,
     *     legacy_virtual_device_id: string,
     *     name: string,
     *     source_adapter: string,
     *     legacy_device_type: string,
     *     is_virtual: bool
     * }>
     */
    private const HUBS = [
        [
            'external_id' => '869244041754767',
            'legacy_virtual_device_id' => '0565b9ec-2912-4ae8-b632-92bc3206f188',
            'name' => 'CLD 02 - Hub',
            'source_adapter' => 'imoni',
            'legacy_device_type' => 'IMoni Hub',
            'is_virtual' => false,
        ],
        [
            'external_id' => '869244041773882',
            'legacy_virtual_device_id' => 'c3d43ea0-0a69-4cf3-8b96-7c07740fc875',
            'name' => 'CLD 03 - Hub',
            'source_adapter' => 'imoni',
            'legacy_device_type' => 'IMoni Hub',
            'is_virtual' => false,
        ],
        [
            'external_id' => '869244041759212',
            'legacy_virtual_device_id' => 'eee742ec-3386-482f-80d1-c2b759618e72',
            'name' => 'CLD 04 - Hub',
            'source_adapter' => 'imoni',
            'legacy_device_type' => 'IMoni Hub',
            'is_virtual' => false,
        ],
        [
            'external_id' => '869244041762430',
            'legacy_virtual_device_id' => '6dc2a9a6-ebbb-423c-99cb-89ef75450b60',
            'name' => 'CLD 05 - Hub',
            'source_adapter' => 'imoni',
            'legacy_device_type' => 'IMoni Hub',
            'is_virtual' => false,
        ],
        [
            'external_id' => '869244041759436',
            'legacy_virtual_device_id' => '4e4f6395-1d26-4022-a5db-d066666759d2',
            'name' => 'CLD 06 - Hub',
            'source_adapter' => 'imoni',
            'legacy_device_type' => 'IMoni Hub',
            'is_virtual' => false,
        ],
        [
            'external_id' => '868728037384267',
            'legacy_virtual_device_id' => '1476978a-899f-41a9-99db-d563176a1e75',
            'name' => 'CLD 07 - Hub',
            'source_adapter' => 'imoni',
            'legacy_device_type' => 'IMoni Hub',
            'is_virtual' => false,
        ],
        [
            'external_id' => '869244041748710',
            'legacy_virtual_device_id' => 'b4c49e22-f5e6-49e2-ab17-bc0c599dd1d2',
            'name' => 'CLD 08 - Hub',
            'source_adapter' => 'imoni',
            'legacy_device_type' => 'IMoni Hub',
            'is_virtual' => false,
        ],
        [
            'external_id' => '869244041751078',
            'legacy_virtual_device_id' => '427eee53-ab01-48a7-996f-f0690fd2cbad',
            'name' => 'CLD 09 - Hub',
            'source_adapter' => 'imoni',
            'legacy_device_type' => 'IMoni Hub',
            'is_virtual' => false,
        ],
        [
            'external_id' => '869244041747860',
            'legacy_virtual_device_id' => '821699d6-2756-4f04-9d85-3438619c1479',
            'name' => 'CLD 10 - Hub',
            'source_adapter' => 'imoni',
            'legacy_device_type' => 'IMoni Hub',
            'is_virtual' => false,
        ],
        [
            'external_id' => '869244041755228',
            'legacy_virtual_device_id' => '610f0c0f-a206-405c-9ced-5a14c5005c05',
            'name' => 'CLD 11 - Hub',
            'source_adapter' => 'imoni',
            'legacy_device_type' => 'IMoni Hub',
            'is_virtual' => false,
        ],
    ];

    /**
     * @var array<string, array<string, string>>
     */
    private const SOURCE_PARAMETER_PROFILES = [
        'imoni_temp_humidity_17_18' => [
            'temperature' => '$.io_17_value',
            'humidity' => '$.io_18_value',
        ],
        'imoni_temp_humidity_19_20' => [
            'temperature' => '$.io_19_value',
            'humidity' => '$.io_20_value',
        ],
        'imoni_temp_17' => [
            'temperature' => '$.io_17_value',
        ],
        'imoni_temp_19' => [
            'temperature' => '$.io_19_value',
        ],
        'imoni_temp_21' => [
            'temperature' => '$.io_21_value',
        ],
        'imoni_temp_23' => [
            'temperature' => '$.io_23_value',
        ],
        'egravity_temp_1' => [
            'temperature' => '$.temp_1',
        ],
        'egravity_temp_1_battery' => [
            'temperature' => '$.temp_1',
            'battery' => '$.batt',
        ],
        'egravity_multi' => [
            'signal' => '$.gsm_sl',
            'battery' => '$.batt',
            'temperature_2' => '$.temp_2',
            'external_power' => '$.pext',
        ],
    ];

    /**
     * @var array<int, array{
     *     external_id: string,
     *     parent_legacy_virtual_device_id: string|null,
     *     name: string,
     *     legacy_name?: string,
     *     source_adapter: string,
     *     legacy_device_type: string,
     *     schema_variant: string,
     *     parameter_profile: string,
     *     source_external_id: string|null,
     *     offline_alert_enabled: bool,
     *     alert_rule_ids: array<int, string>,
     *     legacy_metadata: array<string, mixed>
     * }>
     */
    private const CHILD_DEVICES = [
        [
            'external_id' => 'ea2b48f3-911f-4c90-88b7-29ac47799ed7',
            'parent_legacy_virtual_device_id' => '0565b9ec-2912-4ae8-b632-92bc3206f188',
            'name' => 'CLD 02',
            'legacy_name' => 'ACLD 02',
            'source_adapter' => 'imoni',
            'legacy_device_type' => 'Temperature-Humidity Combined',
            'schema_variant' => 'climate',
            'parameter_profile' => 'imoni_temp_humidity_17_18',
            'source_external_id' => null,
            'offline_alert_enabled' => true,
            'alert_rule_ids' => [],
            'legacy_metadata' => [],
        ],
        [
            'external_id' => '009C56ED',
            'parent_legacy_virtual_device_id' => null,
            'name' => 'CLD 03',
            'legacy_name' => 'CLD03 - 02',
            'source_adapter' => 'egravity',
            'legacy_device_type' => 'Temperature-EG',
            'schema_variant' => 'egravity',
            'parameter_profile' => 'egravity_temp_1_battery',
            'source_external_id' => '009C56ED',
            'offline_alert_enabled' => true,
            'alert_rule_ids' => ['65e7e863a1855a81740279e3'],
            'legacy_metadata' => [
                'manufacturer' => 'Egravity',
                'legacy_name' => 'CLD - 03',
            ],
        ],
        [
            'external_id' => 'e60ed5a0-8bba-46c4-9ba5-e94aacf4e291',
            'parent_legacy_virtual_device_id' => 'eee742ec-3386-482f-80d1-c2b759618e72',
            'name' => 'CLD 04-01',
            'legacy_name' => 'ACLD 04 - 01',
            'source_adapter' => 'imoni',
            'legacy_device_type' => 'Temperature-Humidity Combined',
            'schema_variant' => 'climate',
            'parameter_profile' => 'imoni_temp_humidity_17_18',
            'source_external_id' => null,
            'offline_alert_enabled' => true,
            'alert_rule_ids' => [],
            'legacy_metadata' => [],
        ],
        [
            'external_id' => '794e0d28-3af5-4524-9b9b-4c61551acaa1',
            'parent_legacy_virtual_device_id' => 'eee742ec-3386-482f-80d1-c2b759618e72',
            'name' => 'CLD 04 - 02',
            'source_adapter' => 'imoni',
            'legacy_device_type' => 'IMoni Temperature Node',
            'schema_variant' => 'climate',
            'parameter_profile' => 'imoni_temp_21',
            'source_external_id' => null,
            'offline_alert_enabled' => false,
            'alert_rule_ids' => ['65e7ea30c26dbbfc74049a32'],
            'legacy_metadata' => [],
        ],
        [
            'external_id' => '8a4681e8-8f90-416d-963f-9d1933962b28',
            'parent_legacy_virtual_device_id' => '6dc2a9a6-ebbb-423c-99cb-89ef75450b60',
            'name' => 'CLD 05-01',
            'legacy_name' => 'ACLD 05 - 01',
            'source_adapter' => 'imoni',
            'legacy_device_type' => 'Temperature-Humidity Combined',
            'schema_variant' => 'climate',
            'parameter_profile' => 'imoni_temp_humidity_17_18',
            'offline_alert_enabled' => true,
            'alert_rule_ids' => [],
            'legacy_metadata' => [],
        ],
        [
            'external_id' => 'e8f4681b-b636-4ab1-a67c-a67f2d889758',
            'parent_legacy_virtual_device_id' => '6dc2a9a6-ebbb-423c-99cb-89ef75450b60',
            'name' => 'CLD 05 - 02',
            'source_adapter' => 'imoni',
            'legacy_device_type' => 'IMoni Temperature Node',
            'schema_variant' => 'climate',
            'parameter_profile' => 'imoni_temp_21',
            'offline_alert_enabled' => false,
            'alert_rule_ids' => ['65e7ea7c7b5e2b1947017993'],
            'legacy_metadata' => [],
        ],
        [
            'external_id' => '534aa655-716a-4eb0-9167-8c32eff01fa6',
            'parent_legacy_virtual_device_id' => '4e4f6395-1d26-4022-a5db-d066666759d2',
            'name' => 'CLD 06',
            'legacy_name' => 'ACLD 06',
            'source_adapter' => 'imoni',
            'legacy_device_type' => 'Temperature-Humidity Combined',
            'schema_variant' => 'climate',
            'parameter_profile' => 'imoni_temp_humidity_17_18',
            'offline_alert_enabled' => true,
            'alert_rule_ids' => [],
            'legacy_metadata' => [],
        ],
        [
            'external_id' => 'e45dddae-045d-4d32-a820-3b22a1f8ef6b',
            'parent_legacy_virtual_device_id' => '1476978a-899f-41a9-99db-d563176a1e75',
            'name' => 'ACLD 07 - 01',
            'source_adapter' => 'imoni',
            'legacy_device_type' => 'IMoni Temperature Node',
            'schema_variant' => 'climate',
            'parameter_profile' => 'imoni_temp_21',
            'offline_alert_enabled' => false,
            'alert_rule_ids' => [],
            'legacy_metadata' => [],
        ],
        [
            'external_id' => 'db076bb6-3eb4-4e54-84d9-b456e7da868a',
            'parent_legacy_virtual_device_id' => '1476978a-899f-41a9-99db-d563176a1e75',
            'name' => 'CLD 07-02',
            'legacy_name' => 'ACLD 07 - 02',
            'source_adapter' => 'imoni',
            'legacy_device_type' => 'IMoni Temperature Node',
            'schema_variant' => 'climate',
            'parameter_profile' => 'imoni_temp_23',
            'offline_alert_enabled' => true,
            'alert_rule_ids' => [],
            'legacy_metadata' => [],
        ],
        [
            'external_id' => '96c83780-7370-4ae6-8df4-ce3048613429',
            'parent_legacy_virtual_device_id' => 'b4c49e22-f5e6-49e2-ab17-bc0c599dd1d2',
            'name' => 'ACLD 08 - 01',
            'source_adapter' => 'imoni',
            'legacy_device_type' => 'IMoni Temperature Node',
            'schema_variant' => 'climate',
            'parameter_profile' => 'imoni_temp_21',
            'offline_alert_enabled' => true,
            'alert_rule_ids' => [],
            'legacy_metadata' => [],
        ],
        [
            'external_id' => '27255258-a606-4627-9127-0586adc2512b',
            'parent_legacy_virtual_device_id' => 'b4c49e22-f5e6-49e2-ab17-bc0c599dd1d2',
            'name' => 'CLD 08 - 02',
            'source_adapter' => 'imoni',
            'legacy_device_type' => 'IMoni Temperature Node',
            'schema_variant' => 'climate',
            'parameter_profile' => 'imoni_temp_23',
            'offline_alert_enabled' => false,
            'alert_rule_ids' => ['65e7ed8c3bb512a0e40d8323'],
            'legacy_metadata' => [],
        ],
        [
            'external_id' => '374927dc-2dc6-4c9d-a82d-09ee94887b1a',
            'parent_legacy_virtual_device_id' => '610f0c0f-a206-405c-9ced-5a14c5005c05',
            'name' => 'CLD 11-01',
            'legacy_name' => 'ACLD 11 - 01',
            'source_adapter' => 'imoni',
            'legacy_device_type' => 'IMoni Temperature Node',
            'schema_variant' => 'climate',
            'parameter_profile' => 'imoni_temp_21',
            'offline_alert_enabled' => true,
            'alert_rule_ids' => [],
            'legacy_metadata' => [],
        ],
        [
            'external_id' => '7fc47852-cb20-44c0-a5ec-0e56ed1d0bf4',
            'parent_legacy_virtual_device_id' => '821699d6-2756-4f04-9d85-3438619c1479',
            'name' => 'CLD 10',
            'legacy_name' => 'ACLD 10',
            'source_adapter' => 'imoni',
            'legacy_device_type' => 'Temperature-Humidity Combined',
            'schema_variant' => 'climate',
            'parameter_profile' => 'imoni_temp_humidity_17_18',
            'offline_alert_enabled' => true,
            'alert_rule_ids' => ['65e7eedce09437f4ad048a94', '6693449225c916423c0336d2'],
            'legacy_metadata' => [],
        ],
        [
            'external_id' => 'fefc6916-e88f-4b74-b580-a3f4de31e732',
            'parent_legacy_virtual_device_id' => '610f0c0f-a206-405c-9ced-5a14c5005c05',
            'name' => 'CLD 11 - 02',
            'source_adapter' => 'imoni',
            'legacy_device_type' => 'IMoni Temperature Node',
            'schema_variant' => 'climate',
            'parameter_profile' => 'imoni_temp_23',
            'offline_alert_enabled' => false,
            'alert_rule_ids' => ['65e7f02d3bb512a0e40d8324'],
            'legacy_metadata' => [],
        ],
        [
            'external_id' => 'e5ef1ca9-86e6-44f5-9c7a-0f228d0e9c15',
            'parent_legacy_virtual_device_id' => '427eee53-ab01-48a7-996f-f0690fd2cbad',
            'name' => 'CLD 09-02',
            'legacy_name' => 'CLD 09 - 02',
            'source_adapter' => 'imoni',
            'legacy_device_type' => 'IMoni Temperature Node',
            'schema_variant' => 'climate',
            'parameter_profile' => 'imoni_temp_21',
            'offline_alert_enabled' => true,
            'alert_rule_ids' => ['65e7f03234a0a449610037a5'],
            'legacy_metadata' => [],
        ],
        [
            'external_id' => '00841B48',
            'parent_legacy_virtual_device_id' => null,
            'name' => 'CLD 08-04',
            'legacy_name' => 'CLD08 - 04',
            'source_adapter' => 'egravity',
            'legacy_device_type' => 'Temperature-EG',
            'schema_variant' => 'egravity',
            'parameter_profile' => 'egravity_multi',
            'source_external_id' => '00841B48',
            'offline_alert_enabled' => true,
            'alert_rule_ids' => [],
            'legacy_metadata' => [
                'manufacturer' => 'Egravity',
            ],
        ],
    ];

    public function run(): void
    {
        $organization = Organization::withTrashed()->updateOrCreate(
            ['slug' => self::ORGANIZATION_SLUG],
            [
                'name' => self::ORGANIZATION_NAME,
                'deleted_at' => null,
            ],
        );

        $hubSchemaVersion = $this->upsertHubSchemaVersion();
        $climateSchemaVersion = $this->upsertClimateSchemaVersion();
        $egravitySchemaVersion = $this->upsertEgravitySchemaVersion();

        /** @var array<string, Device> $hubs */
        $hubs = [];

        foreach (self::HUBS as $hubConfig) {
            $hubs[$hubConfig['legacy_virtual_device_id']] = Device::withTrashed()->updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'external_id' => $hubConfig['external_id'],
                ],
                [
                    'device_profile_version_id' => $this->profileVersionForSchemaVersion($hubSchemaVersion)->id,
                    'parent_device_id' => null,
                    'name' => $hubConfig['name'],
                    'metadata' => [
                        'migration_origin' => self::ORGANIZATION_SLUG,
                        'migration_role' => 'hub',
                        'source_adapter' => $hubConfig['source_adapter'],
                        'legacy_device_type' => $hubConfig['legacy_device_type'],
                        'legacy_virtual_device_id' => $hubConfig['legacy_virtual_device_id'],
                        'legacy_is_virtual' => $hubConfig['is_virtual'],
                    ],
                    'is_active' => true,
                    'connection_state' => 'offline',
                    'last_seen_at' => null,
                    'deleted_at' => null,
                ],
            );
        }

        foreach (self::CHILD_DEVICES as $deviceConfig) {
            $parentDevice = null;
            $parentLegacyVirtualDeviceId = $deviceConfig['parent_legacy_virtual_device_id'] ?? null;

            if (is_string($parentLegacyVirtualDeviceId)) {
                $parentDevice = $hubs[$parentLegacyVirtualDeviceId] ?? null;
            }

            if ($parentLegacyVirtualDeviceId !== null && ! $parentDevice instanceof Device) {
                continue;
            }

            $schemaVersion = $deviceConfig['schema_variant'] === 'egravity'
                ? $egravitySchemaVersion
                : $climateSchemaVersion;

            $device = $this->upsertChildDevice(
                organization: $organization,
                parentDevice: $parentDevice,
                schemaVersion: $schemaVersion,
                externalId: $deviceConfig['external_id'],
                name: $deviceConfig['name'],
                metadata: [
                    'migration_origin' => self::ORGANIZATION_SLUG,
                    'migration_role' => 'physical_device',
                    'schema_variant' => $deviceConfig['schema_variant'],
                    'source_adapter' => $deviceConfig['source_adapter'],
                    'legacy_device_name' => $deviceConfig['legacy_name'] ?? $deviceConfig['name'],
                    'legacy_device_type' => $deviceConfig['legacy_device_type'],
                    'legacy_virtual_device_id' => $deviceConfig['external_id'],
                    'legacy_hub_virtual_device_id' => $parentLegacyVirtualDeviceId,
                    'legacy_parameter_profile' => $deviceConfig['parameter_profile'],
                    'legacy_parameter_map' => $this->parametersForProfile($deviceConfig['parameter_profile']),
                    'legacy_source_external_id' => $deviceConfig['source_external_id'] ?? null,
                    'legacy_offline_alert_enabled' => $deviceConfig['offline_alert_enabled'],
                    'legacy_alert_rule_ids' => $deviceConfig['alert_rule_ids'],
                    'legacy_metadata' => $deviceConfig['legacy_metadata'],
                ],
            );

            $this->syncBindings($device, $parentDevice, $schemaVersion, $deviceConfig);
        }

        $this->cleanupRetiredDevices($organization);
    }

    private function upsertHubSchemaVersion(): DeviceProfileVersion
    {
        return $this->upsertSchemaVersion(
            deviceTypeKey: self::HUB_DEVICE_TYPE_KEY,
            deviceTypeName: self::HUB_DEVICE_TYPE_NAME,
            baseTopic: self::HUB_BASE_TOPIC,
            schemaName: self::HUB_SCHEMA_NAME,
            topicKey: 'heartbeat',
            topicLabel: 'Heartbeat',
            topicSuffix: 'heartbeat',
            purpose: ChannelPurpose::State,
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

    private function upsertClimateSchemaVersion(): DeviceProfileVersion
    {
        return $this->upsertSchemaVersion(
            deviceTypeKey: self::CLIMATE_DEVICE_TYPE_KEY,
            deviceTypeName: self::CLIMATE_DEVICE_TYPE_NAME,
            baseTopic: self::CLIMATE_BASE_TOPIC,
            schemaName: self::CLIMATE_SCHEMA_NAME,
            topicKey: 'telemetry',
            topicLabel: 'Telemetry',
            topicSuffix: 'telemetry',
            purpose: ChannelPurpose::Telemetry,
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
            notes: 'SriLankan Airlines legacy cold-room sensors recovered from the old iot-demo inventory.',
        );
    }

    private function upsertEgravitySchemaVersion(): DeviceProfileVersion
    {
        return $this->upsertSchemaVersion(
            deviceTypeKey: self::EGRAVITY_DEVICE_TYPE_KEY,
            deviceTypeName: self::EGRAVITY_DEVICE_TYPE_NAME,
            baseTopic: self::EGRAVITY_BASE_TOPIC,
            schemaName: self::EGRAVITY_SCHEMA_NAME,
            topicKey: 'telemetry',
            topicLabel: 'Telemetry',
            topicSuffix: 'telemetry',
            purpose: ChannelPurpose::Telemetry,
            parameters: [
                [
                    'key' => 'temperature',
                    'label' => 'Temperature',
                    'json_path' => '$.temperature',
                    'type' => ParameterDataType::Decimal,
                    'unit' => MetricUnit::Celsius->value,
                    'required' => false,
                    'is_critical' => true,
                    'validation_rules' => ['min' => -250, 'max' => 250, 'category' => 'static'],
                    'sequence' => 1,
                ],
                [
                    'key' => 'temperature_2',
                    'label' => 'Temperature 2',
                    'json_path' => '$.temperature_2',
                    'type' => ParameterDataType::Decimal,
                    'unit' => MetricUnit::Celsius->value,
                    'required' => false,
                    'is_critical' => true,
                    'validation_rules' => ['min' => -250, 'max' => 250, 'category' => 'static'],
                    'sequence' => 2,
                ],
                [
                    'key' => 'signal',
                    'label' => 'Signal Strength',
                    'json_path' => '$.signal',
                    'type' => ParameterDataType::Integer,
                    'unit' => MetricUnit::DecibelMilliwatts->value,
                    'required' => false,
                    'is_critical' => false,
                    'validation_rules' => ['min' => -120, 'max' => 0, 'category' => 'static'],
                    'sequence' => 3,
                ],
                [
                    'key' => 'battery',
                    'label' => 'Battery',
                    'json_path' => '$.battery',
                    'type' => ParameterDataType::Decimal,
                    'required' => false,
                    'is_critical' => false,
                    'validation_rules' => ['min' => 0, 'category' => 'static'],
                    'sequence' => 4,
                ],
                [
                    'key' => 'external_power',
                    'label' => 'External Power',
                    'json_path' => '$.external_power',
                    'type' => ParameterDataType::Boolean,
                    'required' => false,
                    'is_critical' => false,
                    'category' => ParameterCategory::State,
                    'sequence' => 5,
                ],
            ],
            notes: 'SriLankan Airlines Egravity sensor contract recovered from the old iot-demo inventory.',
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
        string $topicKey,
        string $topicLabel,
        string $topicSuffix,
        ChannelPurpose $purpose,
        array $parameters,
        int $version = 1,
        string $status = 'active',
        string $notes = 'SriLankan Airlines migration onboarding schema.',
    ): DeviceProfileVersion {
        $deviceType = DeviceProfile::query()->updateOrCreate(
            [
                'organization_id' => null,
                'key' => $deviceTypeKey,
            ],
            [
                'name' => $deviceTypeName,
            ],
        );

        $schema = $deviceType;

        $schemaVersion = DeviceProfileVersion::query()->firstOrCreate(
            [
                'device_profile_id' => $schema->id,
                'version' => $version,
            ],
            [
                'status' => $status,
                'notes' => $notes,
            ],
        );

        $schemaVersion->fill([
            'status' => $status,
            'notes' => $notes,
        ])->save();

        $profile = DeviceProfile::query()->updateOrCreate(
            [
                'organization_id' => null,
                'key' => $deviceTypeKey,
            ],
            [
                'name' => $deviceTypeName,
                'tags' => null,
            ],
        );

        $profileVersion = DeviceProfileVersion::query()->updateOrCreate(
            [
                'device_profile_id' => $profile->id,
                'version' => $version,
            ],
            [
                'status' => $status,
                'protocol' => Protocol::Mqtt,
                'protocol_config' => (new MqttProtocolConfig(
                    brokerHost: 'nats',
                    brokerPort: 1883,
                    username: null,
                    password: null,
                    useTls: false,
                    baseTopic: $baseTopic,
                    securityMode: MqttSecurityMode::UsernamePassword,
                ))->toArray(),
                'notes' => $notes,
            ],
        );

        $topic = DeviceChannel::query()->updateOrCreate(
            [
                'device_profile_version_id' => $schemaVersion->id,
                'key' => $topicKey,
            ],
            [
                'label' => $topicLabel,
                'direction' => ChannelDirection::Publish,
                'purpose' => $purpose,
                'address' => $topicSuffix,
                'qos' => 1,
                'retain' => false,
                'sequence' => 0,
            ],
        );

        $channel = DeviceChannel::query()->updateOrCreate(
            [
                'device_profile_version_id' => $profileVersion->id,
                'key' => $topicKey,
            ],
            [
                'label' => $topicLabel,
                'direction' => ChannelDirection::Publish,
                'purpose' => $purpose === ChannelPurpose::State ? ChannelPurpose::State : ChannelPurpose::Telemetry,
                'transport' => ChannelTransport::Mqtt,
                'address' => $topicSuffix,
                'qos' => 1,
                'retain' => false,
                'sequence' => 0,
            ],
        );

        foreach ($parameters as $parameter) {
            ProfileParameterDefinition::query()->updateOrCreate(
                [
                    'device_channel_id' => $topic->id,
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
                    'mutation_expression' => $parameter['mutation_expression'] ?? null,
                    'sequence' => $parameter['sequence'] ?? 0,
                    'is_active' => true,
                ],
            );

            ProfileParameterDefinition::query()->updateOrCreate(
                [
                    'device_channel_id' => $channel->id,
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

        ProfileParameterDefinition::query()
            ->where('device_channel_id', $topic->id)
            ->whereNotIn('key', $parameterKeys)
            ->delete();

        ProfileParameterDefinition::query()
            ->where('device_channel_id', $channel->id)
            ->whereNotIn('key', $parameterKeys)
            ->delete();

        return $schemaVersion->fresh(['profile']);
    }

    private function profileVersionForSchemaVersion(DeviceProfileVersion $schemaVersion): DeviceProfileVersion
    {
        $schemaVersion->loadMissing('profile');
        $deviceType = $schemaVersion->profile;

        if (! $deviceType instanceof DeviceProfile) {
            throw new \RuntimeException('Unable to resolve mirrored profile version for schema version.');
        }

        $profileVersion = DeviceProfileVersion::query()
            ->where('version', (int) $schemaVersion->version)
            ->whereHas('profile', fn ($query) => $query
                ->whereNull('organization_id')
                ->where('key', $deviceType->key))
            ->first();

        if (! $profileVersion instanceof DeviceProfileVersion) {
            throw new \RuntimeException("Mirrored profile version [{$deviceType->key}:{$schemaVersion->version}] was not found.");
        }

        return $profileVersion;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function upsertChildDevice(
        Organization $organization,
        ?Device $parentDevice,
        DeviceProfileVersion $schemaVersion,
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
                'device_profile_version_id' => $this->profileVersionForSchemaVersion($schemaVersion)->id,
                'parent_device_id' => $parentDevice?->id,
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

    /**
     * @param  array{
     *     external_id: string,
     *     parent_legacy_virtual_device_id: string|null,
     *     name: string,
     *     source_adapter: string,
     *     legacy_device_type: string,
     *     schema_variant: string,
     *     parameter_profile: string,
     *     source_external_id: string|null,
     *     offline_alert_enabled: bool,
     *     alert_rule_ids: array<int, string>,
     *     legacy_metadata: array<string, mixed>
     * }  $deviceConfig
     */
    private function syncBindings(Device $device, ?Device $parentDevice, DeviceProfileVersion $schemaVersion, array $deviceConfig): void
    {
        $telemetryChannel = $schemaVersion->channels()->where('key', 'telemetry')->first();

        if (! $telemetryChannel instanceof DeviceChannel) {
            return;
        }

        /** @var array<string, ProfileParameterDefinition> $parametersByKey */
        $parametersByKey = $telemetryChannel->parameters()
            ->get()
            ->keyBy('key')
            ->all();

        $channel = DeviceChannel::query()
            ->where('device_profile_version_id', $device->device_profile_version_id)
            ->where('key', 'telemetry')
            ->first();

        if (! $channel instanceof DeviceChannel) {
            return;
        }

        $expectedParameterKeys = [];
        $sourceIdentity = $deviceConfig['source_external_id']
            ?? $parentDevice?->external_id
            ?? $device->external_id;

        if (! is_string($sourceIdentity) || trim($sourceIdentity) === '') {
            return;
        }

        foreach ($this->parametersForProfile($deviceConfig['parameter_profile']) as $parameterKey => $sourceJsonPath) {
            $parameter = $parametersByKey[$parameterKey] ?? null;

            if (! $parameter instanceof ProfileParameterDefinition) {
                continue;
            }

            $expectedParameterKeys[] = $parameter->key;

            DeviceSignalBinding::query()->updateOrCreate(
                [
                    'device_id' => $device->id,
                    'device_channel_id' => $channel->id,
                    'parameter_key' => $parameter->key,
                ],
                [
                    'source_topic' => $this->sourceTopicFor(
                        sourceAdapter: $deviceConfig['source_adapter'],
                        sourceIdentity: $sourceIdentity,
                    ),
                    'source_json_path' => $sourceJsonPath,
                    'source_adapter' => $deviceConfig['source_adapter'],
                    'sequence' => count($expectedParameterKeys) - 1,
                    'is_active' => true,
                    'metadata' => [
                        'migration_origin' => self::ORGANIZATION_SLUG,
                        'schema_variant' => $deviceConfig['schema_variant'],
                        'legacy_source_path' => $sourceJsonPath,
                    ],
                ],
            );
        }

        DeviceSignalBinding::query()
            ->where('device_id', $device->id)
            ->when(
                $expectedParameterKeys !== [],
                fn ($query) => $query->whereNotIn('parameter_key', $expectedParameterKeys),
            )
            ->delete();
    }

    private function cleanupRetiredDevices(Organization $organization): void
    {
        $expectedExternalIds = array_merge(
            array_column(self::HUBS, 'external_id'),
            array_column(self::CHILD_DEVICES, 'external_id'),
        );

        Device::withTrashed()
            ->where('organization_id', $organization->id)
            ->get()
            ->filter(function (Device $device) use ($expectedExternalIds): bool {
                return ($device->metadata['migration_origin'] ?? null) === self::ORGANIZATION_SLUG
                    && is_string($device->external_id)
                    && ! in_array($device->external_id, $expectedExternalIds, true);
            })
            ->sortByDesc(fn (Device $device): bool => $device->parent_device_id !== null)
            ->each(fn (Device $device): ?bool => $device->forceDelete());
    }

    /**
     * @return array<string, string>
     */
    private function parametersForProfile(string $profile): array
    {
        return self::SOURCE_PARAMETER_PROFILES[$profile] ?? [];
    }

    private function sourceTopicFor(string $sourceAdapter, string $sourceIdentity): string
    {
        if ($sourceAdapter === 'imoni') {
            return 'migration/source/imoni/'.$sourceIdentity.'/'.self::IMONI_PERIPHERAL_TYPE_HEX.'/telemetry';
        }

        return 'migration/source/'.$sourceAdapter.'/'.$sourceIdentity.'/telemetry';
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
