<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\DataIngestion\Models\DeviceSignalBinding;
use App\Domain\DeviceManagement\Enums\MqttSecurityMode;
use App\Domain\DeviceManagement\Enums\ProtocolType;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Models\DeviceType;
use App\Domain\DeviceManagement\ValueObjects\Protocol\MqttProtocolConfig;
use App\Domain\DeviceSchema\Enums\ParameterCategory;
use App\Domain\DeviceSchema\Enums\ParameterDataType;
use App\Domain\DeviceSchema\Enums\TopicDirection;
use App\Domain\DeviceSchema\Enums\TopicPurpose;
use App\Domain\DeviceSchema\Models\DeviceSchema;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Domain\DeviceSchema\Models\ParameterDefinition;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Domain\Shared\Models\Organization;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use Illuminate\Database\Seeder;

class WitcoMigrationSeeder extends Seeder
{
    public const ORGANIZATION_SLUG = 'witco';

    private const HUB_DEVICE_TYPE_KEY = 'legacy_hub';

    private const HUB_DEVICE_TYPE_NAME = 'Legacy Hub';

    private const HUB_BASE_TOPIC = 'devices/legacy-hub';

    private const HUB_SCHEMA_NAME = 'Legacy Hub Presence';

    private const STATUS_DEVICE_TYPE_KEY = 'imoni_status';

    private const STATUS_DEVICE_TYPE_NAME = 'IMONI Status';

    private const STATUS_BASE_TOPIC = 'devices/imoni-status';

    private const STATUS_SCHEMA_NAME = 'IMONI Status';

    private const STATUS_PERIPHERAL_TYPE_HEX = '00';

    /**
     * @var array<int, array{imei: string, name: string}>
     */
    private const HUBS = [
        [
            'imei' => '869244041754866',
            'name' => '869244041754866',
        ],
        [
            'imei' => '869244041759568',
            'name' => '869244041759568',
        ],
        [
            'imei' => '869244041767199',
            'name' => '869244041767199',
        ],
        [
            'imei' => '869244041759279',
            'name' => '869244041759279',
        ],
    ];

    /**
     * @var array<int, string>
     */
    private const OBSOLETE_CHILD_EXTERNAL_IDS = [
        '869244041754866-00',
        '869244041759279-00',
        '869244041759568-00',
        '869244041767199-00',
        '869244041754866-server',
        '869244041759568-a1',
        '869244041767199-ps',
        '869244041759279-a2',
    ];

    /**
     * @var array<int, string>
     */
    private const OBSOLETE_DEVICE_TYPE_KEYS = [
        'witco_imoni_lite',
        'witco_legacy_hub',
        'witco_status',
        'migration_legacy_hub',
        'migration_imoni_peripheral_00',
        'migration_imoni_peripheral_11',
        'migration_imoni_peripheral_12',
    ];

    /**
     * @var array<int, array{
     *     hub_imei: string,
     *     label: string,
     *     io_number: int
     * }>
     */
    private const STATUS_FIELD_MAPPINGS = [
        [
            'hub_imei' => '869244041754866',
            'label' => 'Water Tank Alarm Level',
            'io_number' => 2,
        ],
        [
            'hub_imei' => '869244041754866',
            'label' => 'TH & RH Input - Server room',
            'io_number' => 3,
        ],
        [
            'hub_imei' => '869244041759568',
            'label' => 'CCTV System Alarm',
            'io_number' => 1,
        ],
        [
            'hub_imei' => '869244041759568',
            'label' => 'Access Control System Alarm',
            'io_number' => 2,
        ],
        [
            'hub_imei' => '869244041767199',
            'label' => 'Fire Alarm Panel',
            'io_number' => 1,
        ],
        [
            'hub_imei' => '869244041767199',
            'label' => 'UPS Alarm Status',
            'io_number' => 2,
        ],
        [
            'hub_imei' => '869244041759279',
            'label' => 'Rear Door Status',
            'io_number' => 1,
        ],
        [
            'hub_imei' => '869244041759279',
            'label' => 'Main Door Status',
            'io_number' => 2,
        ],
        [
            'hub_imei' => '869244041759279',
            'label' => 'TH & RH - GF UPS room',
            'io_number' => 3,
        ],
    ];

    public function run(): void
    {
        $organization = Organization::withTrashed()->updateOrCreate(
            ['slug' => self::ORGANIZATION_SLUG],
            [
                'name' => 'WITCO',
                'deleted_at' => null,
            ],
        );

        $this->pruneObsoleteSchemaArtifacts();

        $hubSchemaVersion = $this->upsertSchemaVersion(
            deviceTypeKey: self::HUB_DEVICE_TYPE_KEY,
            deviceTypeName: self::HUB_DEVICE_TYPE_NAME,
            baseTopic: self::HUB_BASE_TOPIC,
            schemaName: self::HUB_SCHEMA_NAME,
            topicKey: 'heartbeat',
            topicLabel: 'Heartbeat',
            topicSuffix: 'heartbeat',
            purpose: TopicPurpose::State,
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

        $statusSchemaVersion = $this->upsertSchemaVersion(
            deviceTypeKey: self::STATUS_DEVICE_TYPE_KEY,
            deviceTypeName: self::STATUS_DEVICE_TYPE_NAME,
            baseTopic: self::STATUS_BASE_TOPIC,
            schemaName: self::STATUS_SCHEMA_NAME,
            topicKey: 'telemetry',
            topicLabel: 'Telemetry',
            topicSuffix: 'telemetry',
            purpose: TopicPurpose::Telemetry,
            parameters: [
                [
                    'key' => 'status',
                    'label' => 'Status',
                    'json_path' => '$.status',
                    'type' => ParameterDataType::Integer,
                    'category' => ParameterCategory::State,
                    'required' => false,
                    'validation_rules' => [
                        'min' => 0,
                        'max' => 1,
                    ],
                    'control_ui' => [
                        'state_mappings' => [
                            ['value' => 0, 'label' => 'OFF', 'color' => '#ef4444'],
                            ['value' => 1, 'label' => 'ON', 'color' => '#22c55e'],
                        ],
                    ],
                    'mutation_expression' => [
                        'if' => [
                            [
                                '===' => [
                                    ['var' => 'val'],
                                    1,
                                ],
                            ],
                            0,
                            1,
                        ],
                    ],
                    'sequence' => 1,
                ],
            ],
        );

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

        $this->pruneObsoleteChildren($organization);

        $statusTopic = $statusSchemaVersion->topics()->where('key', 'telemetry')->first();
        $statusParameter = $statusTopic?->parameters()->where('key', 'status')->first();

        if (! $statusParameter instanceof ParameterDefinition) {
            throw new \RuntimeException('WITCO status parameter definition could not be resolved.');
        }

        foreach (self::STATUS_FIELD_MAPPINGS as $mapping) {
            $parentDevice = $hubs[$mapping['hub_imei']] ?? null;

            if (! $parentDevice instanceof Device) {
                continue;
            }

            $device = $this->upsertChildDevice(
                organization: $organization,
                parentDevice: $parentDevice,
                schemaVersion: $statusSchemaVersion,
                externalId: $this->physicalDeviceExternalId($mapping),
                name: $mapping['label'],
                metadata: [
                    'migration_role' => 'physical_device',
                    'source_adapter' => 'imoni',
                ],
            );

            DeviceSignalBinding::query()->updateOrCreate(
                [
                    'device_id' => $device->id,
                    'parameter_definition_id' => $statusParameter->id,
                ],
                [
                    'source_topic' => $this->sourceTopicFor($mapping['hub_imei'], self::STATUS_PERIPHERAL_TYPE_HEX),
                    'source_json_path' => '$.io_'.$mapping['io_number'].'_value',
                    'source_adapter' => 'imoni',
                    'sequence' => 0,
                    'is_active' => true,
                    'metadata' => [],
                ],
            );
        }
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
        TopicPurpose $purpose,
        array $parameters,
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
                'version' => 1,
            ],
            [
                'status' => 'active',
                'notes' => 'Migration onboarding schema.',
            ],
        );

        if ($schemaVersion->status !== 'active') {
            $schemaVersion->update([
                'status' => 'active',
                'notes' => 'Migration onboarding schema.',
            ]);
        }

        $topic = SchemaVersionTopic::query()->updateOrCreate(
            [
                'device_schema_version_id' => $schemaVersion->id,
                'key' => $topicKey,
            ],
            [
                'label' => $topicLabel,
                'direction' => TopicDirection::Publish,
                'purpose' => $purpose,
                'suffix' => $topicSuffix,
                'description' => 'Migration onboarding topic.',
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

    private function pruneObsoleteChildren(Organization $organization): void
    {
        Device::withTrashed()
            ->where('organization_id', $organization->id)
            ->whereIn('external_id', self::OBSOLETE_CHILD_EXTERNAL_IDS)
            ->get()
            ->each(function (Device $device): void {
                $device->telemetryLogs()->delete();
                $device->forceDelete();
            });
    }

    private function sourceTopicFor(string $hubImei, string $peripheralTypeHex): string
    {
        return 'migration/source/imoni/'.$hubImei.'/'.strtoupper($peripheralTypeHex).'/telemetry';
    }

    /**
     * @param  array{hub_imei: string, label: string, io_number: int}  $mapping
     */
    private function physicalDeviceExternalId(array $mapping): string
    {
        return $mapping['hub_imei']
            .'-'
            .self::STATUS_PERIPHERAL_TYPE_HEX
            .'-'
            .str_pad((string) $mapping['io_number'], 2, '0', STR_PAD_LEFT);
    }

    private function pruneObsoleteSchemaArtifacts(): void
    {
        $obsoleteDeviceTypes = DeviceType::query()
            ->whereIn('key', self::OBSOLETE_DEVICE_TYPE_KEYS)
            ->get();

        if ($obsoleteDeviceTypes->isEmpty()) {
            return;
        }

        $obsoleteTypeIds = $obsoleteDeviceTypes->pluck('id');

        $obsoleteSchemaVersions = DeviceSchemaVersion::query()
            ->whereHas('schema', fn ($query) => $query->withTrashed()->whereIn('device_type_id', $obsoleteTypeIds))
            ->get();

        $obsoleteVersionIds = $obsoleteSchemaVersions->pluck('id');

        DeviceTelemetryLog::query()
            ->whereIn('device_schema_version_id', $obsoleteVersionIds)
            ->delete();

        Device::withTrashed()
            ->whereIn('device_type_id', $obsoleteTypeIds)
            ->get()
            ->each(fn (Device $device) => $device->forceDelete());

        $obsoleteSchemaVersions->each->delete();

        DeviceSchema::withTrashed()
            ->whereIn('device_type_id', $obsoleteTypeIds)
            ->get()
            ->each(fn (DeviceSchema $schema) => $schema->forceDelete());

        $obsoleteDeviceTypes->each->delete();
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
}
