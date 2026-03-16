<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\DeviceManagement\Enums\MqttSecurityMode;
use App\Domain\DeviceManagement\Enums\ProtocolType;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Models\DeviceType;
use App\Domain\DeviceManagement\ValueObjects\Protocol\MqttProtocolConfig;
use App\Domain\DeviceSchema\Enums\ParameterDataType;
use App\Domain\DeviceSchema\Enums\TopicDirection;
use App\Domain\DeviceSchema\Enums\TopicPurpose;
use App\Domain\DeviceSchema\Models\DeviceSchema;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Domain\DeviceSchema\Models\ParameterDefinition;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Domain\Shared\Models\Organization;
use Illuminate\Database\Seeder;

class MigrationRehearsalSeeder extends Seeder
{
    private const ORGANIZATION_SLUG = 'migration-rehearsal';

    private const HUB_EXTERNAL_ID = '869244049087921';

    public function run(): void
    {
        $organization = Organization::query()->firstOrCreate(
            ['slug' => self::ORGANIZATION_SLUG],
            ['name' => 'Migration Rehearsal'],
        );

        $hubSchemaVersion = $this->upsertSchemaVersion(
            organization: $organization,
            deviceTypeKey: 'migration_legacy_hub',
            deviceTypeName: 'Migration Legacy Hub',
            baseTopic: 'migration/hubs',
            schemaName: 'Migration Hub Contract',
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

        $hub = Device::query()->updateOrCreate(
            [
                'organization_id' => $organization->id,
                'external_id' => self::HUB_EXTERNAL_ID,
            ],
            [
                'device_type_id' => $hubSchemaVersion->schema->device_type_id,
                'device_schema_version_id' => $hubSchemaVersion->id,
                'parent_device_id' => null,
                'name' => 'IMoni Hub 869244049087921',
                'metadata' => [
                    'migration_role' => 'hub',
                    'legacy_source_id' => self::HUB_EXTERNAL_ID,
                    'imei' => self::HUB_EXTERNAL_ID,
                ],
                'is_active' => true,
                'connection_state' => 'offline',
                'last_seen_at' => null,
            ],
        );

        $imoniLiteSchemaVersion = $this->upsertSchemaVersion(
            organization: $organization,
            deviceTypeKey: 'migration_imoni_peripheral_00',
            deviceTypeName: 'Migration IMoni Peripheral 00',
            baseTopic: 'migration/imoni-lite',
            schemaName: 'Migration IMoni Lite Contract',
            topicKey: 'telemetry',
            topicLabel: 'Telemetry',
            topicSuffix: 'telemetry',
            purpose: TopicPurpose::Telemetry,
            parameters: $this->peripheralParameters(
                ioNumbers: [1, 2, 3, 4, 15, 16, 25, 26, 27, 28, 29, 30, 31],
            ),
        );

        $ioExt1SchemaVersion = $this->upsertSchemaVersion(
            organization: $organization,
            deviceTypeKey: 'migration_imoni_peripheral_11',
            deviceTypeName: 'Migration IMoni Peripheral 11',
            baseTopic: 'migration/ioext1',
            schemaName: 'Migration IOext1 Contract',
            topicKey: 'telemetry',
            topicLabel: 'Telemetry',
            topicSuffix: 'telemetry',
            purpose: TopicPurpose::Telemetry,
            parameters: $this->peripheralParameters(
                ioNumbers: [1, 2, 3, 4, 5, 6, 7],
            ),
        );

        $ioExt2SchemaVersion = $this->upsertSchemaVersion(
            organization: $organization,
            deviceTypeKey: 'migration_imoni_peripheral_12',
            deviceTypeName: 'Migration IMoni Peripheral 12',
            baseTopic: 'migration/ioext2',
            schemaName: 'Migration IOext2 Contract',
            topicKey: 'telemetry',
            topicLabel: 'Telemetry',
            topicSuffix: 'telemetry',
            purpose: TopicPurpose::Telemetry,
            parameters: $this->peripheralParameters(
                ioNumbers: [1, 2, 3, 4, 5, 6, 7],
            ),
        );

        $this->upsertChildDevice(
            organization: $organization,
            parentDevice: $hub,
            schemaVersion: $imoniLiteSchemaVersion,
            externalId: self::HUB_EXTERNAL_ID.'-00',
            name: 'IMoni Peripheral 00',
            metadata: [
                'migration_role' => 'child',
                'legacy_child_id' => self::HUB_EXTERNAL_ID.'-00',
                'legacy_behavior' => 'imoni_peripheral',
                'peripheral_name' => 'iMoni_LITE',
                'peripheral_type_hex' => '00',
            ],
        );

        $this->upsertChildDevice(
            organization: $organization,
            parentDevice: $hub,
            schemaVersion: $ioExt1SchemaVersion,
            externalId: self::HUB_EXTERNAL_ID.'-11',
            name: 'IMoni Peripheral 11',
            metadata: [
                'migration_role' => 'child',
                'legacy_child_id' => self::HUB_EXTERNAL_ID.'-11',
                'legacy_behavior' => 'imoni_peripheral',
                'peripheral_name' => 'IOext1',
                'peripheral_type_hex' => '11',
            ],
        );

        $this->upsertChildDevice(
            organization: $organization,
            parentDevice: $hub,
            schemaVersion: $ioExt2SchemaVersion,
            externalId: self::HUB_EXTERNAL_ID.'-12',
            name: 'IMoni Peripheral 12',
            metadata: [
                'migration_role' => 'child',
                'legacy_child_id' => self::HUB_EXTERNAL_ID.'-12',
                'legacy_behavior' => 'imoni_peripheral',
                'peripheral_name' => 'IOext2',
                'peripheral_type_hex' => '12',
            ],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $parameters
     */
    private function upsertSchemaVersion(
        Organization $organization,
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
                'organization_id' => $organization->id,
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
                'notes' => 'Local migration rehearsal schema.',
            ],
        );

        if ($schemaVersion->status !== 'active') {
            $schemaVersion->update([
                'status' => 'active',
                'notes' => 'Local migration rehearsal schema.',
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
                'description' => 'Local migration rehearsal topic.',
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
                    'validation_rules' => $parameter['validation_rules'] ?? null,
                    'sequence' => $parameter['sequence'] ?? 0,
                    'is_active' => true,
                ],
            );
        }

        return $schemaVersion->fresh(['schema']);
    }

    /**
     * @param  list<int>  $ioNumbers
     * @return array<int, array<string, mixed>>
     */
    private function peripheralParameters(array $ioNumbers): array
    {
        $parameters = [
            [
                'key' => 'peripheral_name',
                'label' => 'Peripheral Name',
                'json_path' => '$.peripheral_name',
                'type' => ParameterDataType::String,
                'required' => true,
                'sequence' => 1,
            ],
            [
                'key' => 'peripheral_type_hex',
                'label' => 'Peripheral Type Hex',
                'json_path' => '$.peripheral_type_hex',
                'type' => ParameterDataType::String,
                'required' => true,
                'sequence' => 2,
            ],
        ];

        $sequence = 3;

        foreach ($ioNumbers as $ioNumber) {
            $parameters[] = [
                'key' => 'io_'.$ioNumber.'_value',
                'label' => 'IO '.$ioNumber.' Value',
                'json_path' => '$.io_'.$ioNumber.'_value',
                'type' => ParameterDataType::Decimal,
                'required' => false,
                'sequence' => $sequence,
            ];
            $sequence++;

            $parameters[] = [
                'key' => 'io_'.$ioNumber.'_action',
                'label' => 'IO '.$ioNumber.' Action',
                'json_path' => '$.io_'.$ioNumber.'_action',
                'type' => ParameterDataType::String,
                'required' => false,
                'sequence' => $sequence,
            ];
            $sequence++;

            $parameters[] = [
                'key' => 'io_'.$ioNumber.'_raw_hex',
                'label' => 'IO '.$ioNumber.' Raw Hex',
                'json_path' => '$.io_'.$ioNumber.'_raw_hex',
                'type' => ParameterDataType::String,
                'required' => false,
                'sequence' => $sequence,
            ];
            $sequence++;
        }

        return $parameters;
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
    ): void {
        Device::query()->updateOrCreate(
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
            ],
        );
    }
}
