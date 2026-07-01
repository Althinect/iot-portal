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
use App\Domain\DeviceProfile\Enums\ParameterCategory;
use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Enums\Protocol;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\DeviceProfile;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use App\Domain\Shared\Models\Organization;
use Illuminate\Database\Seeder;

class WitcoMigrationSeeder extends Seeder
{
    public const ORGANIZATION_SLUG = 'witco';

    private const HUB_DEVICE_TYPE_KEY = 'legacy_hub';

    private const HUB_DEVICE_TYPE_NAME = 'Legacy Hub';

    private const HUB_BASE_TOPIC = 'devices/legacy-hub';

    private const HUB_SCHEMA_NAME = 'Legacy Hub Presence';

    private const STATUS_DEVICE_TYPE_KEY = 'status';

    private const STATUS_DEVICE_TYPE_NAME = 'Status';

    private const STATUS_BASE_TOPIC = 'devices/status';

    private const STATUS_SCHEMA_NAME = 'Status';

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

        $statusSchemaVersion = $this->upsertSchemaVersion(
            deviceTypeKey: self::STATUS_DEVICE_TYPE_KEY,
            deviceTypeName: self::STATUS_DEVICE_TYPE_NAME,
            baseTopic: self::STATUS_BASE_TOPIC,
            schemaName: self::STATUS_SCHEMA_NAME,
            topicKey: 'telemetry',
            topicLabel: 'Telemetry',
            topicSuffix: 'telemetry',
            purpose: ChannelPurpose::Telemetry,
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
                    'device_profile_version_id' => $this->profileVersionForSchemaVersion($hubSchemaVersion)->id,
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

        $statusTopic = $statusSchemaVersion->channels()->where('key', 'telemetry')->first();
        $statusParameter = $statusTopic?->parameters()->where('key', 'status')->first();

        if (! $statusParameter instanceof ProfileParameterDefinition) {
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

            $channel = $this->profileChannelForParameter($device, $statusParameter);

            DeviceSignalBinding::query()->updateOrCreate(
                [
                    'device_id' => $device->id,
                    'device_channel_id' => $channel->id,
                    'parameter_key' => $statusParameter->key,
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
        ChannelPurpose $purpose,
        array $parameters,
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
                'description' => 'Migration onboarding topic.',
                'qos' => 1,
                'retain' => false,
                'sequence' => 0,
            ],
        );

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
                'version' => 1,
            ],
            [
                'status' => 'active',
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
                'notes' => 'Migration onboarding schema.',
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
                'http_method' => '',
                'description' => 'Migration onboarding topic.',
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
                    'control_ui' => $parameter['control_ui'] ?? null,
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

        ProfileParameterDefinition::query()
            ->where('device_channel_id', $topic->id)
            ->whereNotIn('key', $parameterKeys)
            ->delete();

        return $schemaVersion->fresh(['profile']);
    }

    private function profileChannelForParameter(Device $device, ProfileParameterDefinition $parameter): DeviceChannel
    {
        $parameter->loadMissing('channel');

        $channel = DeviceChannel::query()
            ->where('device_profile_version_id', $device->device_profile_version_id)
            ->where('key', $parameter->channel?->key)
            ->first();

        if (! $channel instanceof DeviceChannel) {
            throw new \RuntimeException("Mirrored channel [{$parameter->channel?->key}] was not found for device [{$device->id}].");
        }

        return $channel;
    }

    private function profileVersionForSchemaVersion(DeviceProfileVersion $schemaVersion): DeviceProfileVersion
    {
        $schemaVersion->loadMissing('profile');
        $deviceType = $schemaVersion->profile;

        if (! $deviceType instanceof DeviceProfile) {
            throw new \RuntimeException('Unable to resolve mirrored device profile.');
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
        // Legacy schema artifacts no longer exist in the profile-based schema.
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function upsertChildDevice(
        Organization $organization,
        Device $parentDevice,
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
