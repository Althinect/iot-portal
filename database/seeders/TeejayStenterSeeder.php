<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Models\VirtualDeviceLink;

class TeejayStenterSeeder extends TeejayMigrationSeederSupport
{
    /**
     * @var array<string, mixed>
     */
    private const VIRTUAL_STANDARD_PROFILE = [
        'label' => 'Stenter Standard',
        'description' => 'Virtual stenter standard composed from named physical telemetry sources for status, energy, and production length.',
        'shift_schedule' => [
            'id' => 'teejay_stenter_06_00',
            'label' => 'Teejay Stenter 06:00 / 14:00 / 22:00 Shifts',
        ],
        'sources' => [
            'status' => [
                'label' => 'Status',
                'required' => true,
                'allowed_device_profile_keys' => ['status'],
            ],
            'energy' => [
                'label' => 'Energy',
                'required' => true,
                'allowed_device_profile_keys' => ['energy_meter'],
            ],
            'length' => [
                'label' => 'Length',
                'required' => true,
                'allowed_device_profile_keys' => ['fabric_length_counter'],
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
            notes: 'Recovered Teejay stenter line aggregate inventory with linked component metadata.',
            virtualStandardProfile: self::VIRTUAL_STANDARD_PROFILE,
        );

        $expectedExternalIds = [];

        foreach (TeejayMigrationInventory::stenters() as $stenterConfig) {
            $device = $this->upsertStandaloneDevice(
                organization: $organization,
                schemaVersion: $schemaVersion,
                externalId: $stenterConfig['external_id'],
                name: $stenterConfig['name'],
                metadata: [
                    'migration_origin' => TeejayMigrationSeeder::ORGANIZATION_SLUG,
                    'migration_role' => 'aggregate_device',
                    'migration_device_type' => 'Stenter',
                    'schema_variant' => 'stenter-line',
                    'virtual_standard_profile_key' => self::DEVICE_TYPE_KEY,
                    'virtual_standard_profile_label' => self::VIRTUAL_STANDARD_PROFILE['label'],
                    'virtual_standard_shift_schedule_id' => self::VIRTUAL_STANDARD_PROFILE['shift_schedule']['id'],
                    'virtual_standard_shift_schedule_label' => self::VIRTUAL_STANDARD_PROFILE['shift_schedule']['label'],
                    'virtual_standard_source_purposes' => array_keys(self::VIRTUAL_STANDARD_PROFILE['sources']),
                    'components' => $stenterConfig['components'],
                ],
            );

            $device->forceFill([
                'is_virtual' => true,
                'parent_device_id' => null,
            ])->save();

            $this->syncVirtualStandardLinks($device, $stenterConfig['components']);

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
                        'migration_origin' => TeejayMigrationSeeder::ORGANIZATION_SLUG,
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
