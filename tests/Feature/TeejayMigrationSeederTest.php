<?php

declare(strict_types=1);

use App\Domain\DataIngestion\Models\DeviceSignalBinding;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\Shared\Models\Organization;
use Database\Seeders\TeejayMigrationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

it('seeds the current Teejay legacy inventory with its gateway assignments', function (): void {
    seed(TeejayMigrationSeeder::class);
    seed(TeejayMigrationSeeder::class);

    $organization = Organization::query()
        ->where('slug', TeejayMigrationSeeder::ORGANIZATION_SLUG)
        ->firstOrFail();

    $devices = Device::query()
        ->where('organization_id', $organization->id)
        ->get();

    $countsByMigrationType = $devices
        ->groupBy(fn (Device $device): string => (string) ($device->metadata['migration_device_type'] ?? 'unknown'))
        ->map(fn ($groupedDevices): int => $groupedDevices->count())
        ->all();

    $wtpEtp = Device::query()
        ->where('organization_id', $organization->id)
        ->where('external_id', '8865286073149329-2A')
        ->firstOrFail();
    $finalInspectionFive = Device::query()
        ->where('organization_id', $organization->id)
        ->where('external_id', '8865286073149329-00-01')
        ->firstOrFail();
    $newGateway = Device::query()
        ->where('organization_id', $organization->id)
        ->where('external_id', '869604063874209')
        ->firstOrFail();
    $fourGGateway = Device::query()
        ->where('organization_id', $organization->id)
        ->where('external_id', '8865286073149329')
        ->firstOrFail();

    expect($devices)->toHaveCount(278)
        ->and($countsByMigrationType)->toMatchArray([
            'AC Energy Mate' => 102,
            'Fabric Length' => 14,
            'Fabric Length(Short)' => 8,
            'IMoni Hub' => 33,
            'IMoni Modbus Level Sensor' => 9,
            'Preassure' => 12,
            'Status' => 32,
            'Steam meter' => 14,
            'Stenter' => 10,
            'Temperature' => 16,
            'Water Flow and Volume' => 28,
        ])
        ->and($wtpEtp->parent_device_id)->toBe($newGateway->id)
        ->and($finalInspectionFive->parent_device_id)->toBe($fourGGateway->id)
        ->and($finalInspectionFive->metadata)->toMatchArray([
            'legacy_device_uid' => '869604063846025-00-01',
            'legacy_hub_imei' => '8865286073149329',
        ])
        ->and(DeviceSignalBinding::query()->where('device_id', $finalInspectionFive->id)->value('source_topic'))
        ->toBe('migration/source/imoni/8865286073149329/00/telemetry');
});
