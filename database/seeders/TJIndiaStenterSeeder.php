<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Models\VirtualDeviceLink;

class TJIndiaStenterSeeder extends TJIndiaMigrationSeederSupport
{
    /**
     * @var array<string, mixed>
     */
    private const VIRTUAL_STANDARD_PROFILE = [
        'label' => 'TJ India Production Line',
        'description' => 'Virtual production line composed from named physical telemetry sources for status and production length.',
        'sources' => [
            'status' => [
                'label' => 'Status',
                'required' => true,
                'allowed_device_type_keys' => ['status'],
            ],
            'length' => [
                'label' => 'Length',
                'required' => true,
                'allowed_device_type_keys' => ['fabric_length_counter'],
            ],
        ],
    ];

    private const DEVICE_TYPE_KEY = 'stenter_line';

    private const DEVICE_TYPE_NAME = 'Stenter Line';

    private const BASE_TOPIC = 'lines/stenter';

    private const SCHEMA_NAME = 'Stenter Line Contract';

    public function run(): void
    {
        $organization = $this->ensureOrganization();

        $schemaVersion = $this->upsertSchemaVersion(
            deviceTypeKey: self::DEVICE_TYPE_KEY,
            deviceTypeName: self::DEVICE_TYPE_NAME,
            baseTopic: self::BASE_TOPIC,
            schemaName: self::SCHEMA_NAME,
            parameters: [],
            status: 'draft',
            notes: 'Recovered TJ India production line aggregate inventory with linked component metadata.',
            virtualStandardProfile: self::VIRTUAL_STANDARD_PROFILE,
        );

        $expectedExternalIds = [];

        foreach (TJIndiaMigrationInventory::productionLines() as $productionLineConfig) {
            $device = $this->upsertStandaloneDevice(
                organization: $organization,
                schemaVersion: $schemaVersion,
                externalId: $productionLineConfig['external_id'],
                name: $productionLineConfig['name'],
                metadata: [
                    'migration_origin' => TJIndiaMigrationSeeder::ORGANIZATION_SLUG,
                    'migration_role' => 'aggregate_device',
                    'migration_device_type' => 'Stenter',
                    'schema_variant' => 'stenter-line-status-length',
                    'virtual_standard_profile_key' => self::DEVICE_TYPE_KEY,
                    'virtual_standard_profile_label' => self::VIRTUAL_STANDARD_PROFILE['label'],
                    'virtual_standard_source_purposes' => array_keys(self::VIRTUAL_STANDARD_PROFILE['sources']),
                    'legacy_virtual_device_id' => $productionLineConfig['legacy_virtual_device_id'],
                    'components' => $productionLineConfig['components'],
                ],
            );

            $device->forceFill([
                'is_virtual' => true,
                'parent_device_id' => null,
            ])->save();

            $this->syncVirtualStandardLinks($device, $productionLineConfig['components']);

            $expectedExternalIds[] = $device->external_id;
        }

        $this->cleanupDevices($organization, 'Stenter', $expectedExternalIds);
        $this->cleanupUnusedDraftSchemaVersions(self::DEVICE_TYPE_KEY, self::SCHEMA_NAME, [$schemaVersion->version]);
    }

    /**
     * @param  array<int, array{label: string, component_type: string, component_name: string, component_external_id: string}>  $components
     */
    private function syncVirtualStandardLinks(Device $virtualDevice, array $components): void
    {
        $retainedLinkIds = [];

        foreach (array_values($components) as $index => $component) {
            $sourceDevice = Device::query()
                ->where('organization_id', $virtualDevice->organization_id)
                ->where('external_id', $component['component_external_id'])
                ->where('is_virtual', false)
                ->first(['id']);

            if (! $sourceDevice instanceof Device) {
                continue;
            }

            $link = VirtualDeviceLink::query()->updateOrCreate(
                [
                    'virtual_device_id' => $virtualDevice->id,
                    'source_device_id' => $sourceDevice->id,
                    'purpose' => $component['label'],
                ],
                [
                    'sequence' => $index + 1,
                    'metadata' => [
                        'migration_origin' => TJIndiaMigrationSeeder::ORGANIZATION_SLUG,
                        'legacy_component_type' => $component['component_type'],
                        'legacy_component_name' => $component['component_name'],
                        'legacy_component_external_id' => $component['component_external_id'],
                    ],
                ],
            );

            $retainedLinkIds[] = (int) $link->id;
        }

        $staleLinks = $virtualDevice->virtualDeviceLinks();

        if ($retainedLinkIds !== []) {
            $staleLinks->whereNotIn('id', $retainedLinkIds);
        }

        $staleLinks->delete();
    }
}
