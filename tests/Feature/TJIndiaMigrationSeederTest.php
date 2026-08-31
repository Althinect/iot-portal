<?php

declare(strict_types=1);

use App\Domain\DataIngestion\Models\DeviceSignalBinding;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Models\DeviceProfile;
use App\Domain\Shared\Models\Organization;
use Database\Seeders\TJIndiaFabricLengthSeeder;
use Database\Seeders\TJIndiaHubsSeeder;
use Database\Seeders\TJIndiaMigrationSeeder;
use Database\Seeders\TJIndiaStatusSeeder;
use Database\Seeders\TJIndiaStenterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

it('seeds the full tj india migration inventory through the orchestrator', function (): void {
    seed(TJIndiaMigrationSeeder::class);
    seed(TJIndiaMigrationSeeder::class);

    /** @var Organization $organization */
    $organization = Organization::query()
        ->where('slug', TJIndiaMigrationSeeder::ORGANIZATION_SLUG)
        ->firstOrFail();

    $devices = Device::query()
        ->where('organization_id', $organization?->id)
        ->get();

    $countsByMigrationType = $devices
        ->groupBy(fn (Device $device): string => (string) ($device->metadata['migration_device_type'] ?? 'unknown'))
        ->map(fn ($groupedDevices): int => $groupedDevices->count())
        ->all();

    /** @var Device $compactor01 */
    $compactor01 = Device::query()->where('external_id', 'tj-india-compactor01')->firstOrFail();
    /** @var Device $stenter08 */
    $stenter08 = Device::query()->where('external_id', 'tj-india-stenter08')->firstOrFail();
    /** @var Device $replacementGateway */
    $replacementGateway = Device::query()->where('external_id', '869604063873490')->firstOrFail();
    /** @var Device $compactor01Length */
    $compactor01Length = Device::query()->where('external_id', '869604063798168-52-2')->firstOrFail();
    /** @var Device $stenter05Length */
    $stenter05Length = Device::query()->where('external_id', '869604063873490-51-1')->firstOrFail();

    $compactor01->load('virtualDeviceLinks.sourceDevice');
    $stenter08->load('virtualDeviceLinks.sourceDevice');

    expect($devices)->toHaveCount(42)
        ->and($countsByMigrationType)->toMatchArray([
            'IMoni Hub' => 3,
            'Fabric Length' => 13,
            'Status' => 13,
            'Stenter' => 13,
        ])
        ->and(Device::query()->where('external_id', '869604063873490')->exists())->toBeTrue()
        ->and(Device::query()->where('external_id', '869604063844418-52-2')->exists())->toBeTrue()
        ->and(Device::query()->where('external_id', '869604063798168-00-7')->exists())->toBeTrue()
        ->and($compactor01Length->parent_device_id)->toBe($replacementGateway->id)
        ->and($compactor01Length->metadata)->toMatchArray([
            'legacy_hub_imei' => '869604063798168',
            'legacy_parent_hub_imei' => '869604063873490',
        ])
        ->and($stenter05Length->parent_device_id)->toBe($replacementGateway->id)
        ->and(DeviceSignalBinding::query()->where('device_id', $compactor01Length->id)->value('source_topic'))->toBe('migration/source/imoni/869604063798168/52/telemetry')
        ->and(DeviceSignalBinding::query()->where('device_id', $stenter05Length->id)->value('source_topic'))->toBe('migration/source/imoni/869604063873490/51/telemetry')
        ->and($compactor01->isVirtual())->toBeTrue()
        ->and($compactor01->metadata['components'] ?? [])->toContainEqual([
            'label' => 'length',
            'component_type' => 'Fabric Length',
            'component_name' => 'IN-TJ-Compactor01',
            'component_external_id' => '869604063798168-52-2',
        ])
        ->and($stenter08->virtualDeviceLinks)->toHaveCount(2)
        ->and($stenter08->virtualDeviceLinks->pluck('purpose')->all())->toEqualCanonicalizing(['length', 'status']);
});

it('seeds tj india hubs with canonical source imeis', function (): void {
    seed(TJIndiaHubsSeeder::class);

    $organization = Organization::query()
        ->where('slug', TJIndiaMigrationSeeder::ORGANIZATION_SLUG)
        ->firstOrFail();

    $hubExternalIds = Device::query()
        ->where('organization_id', $organization->id)
        ->whereNull('parent_device_id')
        ->whereHas('profileVersion.profile', fn ($query) => $query->where('key', 'legacy_hub'))
        ->orderBy('external_id')
        ->pluck('external_id')
        ->all();

    expect($hubExternalIds)->toBe([
        '869604063835507',
        '869604063844418',
        '869604063873490',
    ])
        ->and($hubExternalIds)->not->toContain('869604063867849')
        ->and($hubExternalIds)->not->toContain('869604063871734');
});

it('seeds tj india fabric length devices with canonicalized shared-peripheral ids', function (): void {
    seed(TJIndiaFabricLengthSeeder::class);

    $stenter01 = Device::query()->where('name', 'IN-TJ-Stenter01')->firstOrFail();
    $stenter02 = Device::query()->where('name', 'IN-TJ-Stenter02')->firstOrFail();
    $compactor01 = Device::query()->where('name', 'IN-TJ-Compactor01')->firstOrFail();

    $fabricLengthVersions = DeviceProfile::query()
        ->where('key', 'fabric_length_counter')
        ->firstOrFail()
        ->versions()
        ->orderBy('version')
        ->pluck('version')
        ->map(static fn (mixed $version): int => (int) $version)
        ->all();

    expect($stenter01->external_id)->toBe('869604063835507-54-1')
        ->and($stenter02->external_id)->toBe('869604063835507-54-2')
        ->and($compactor01->external_id)->toBe('869604063798168-52-2')
        ->and($fabricLengthVersions)->toBe([1, 2, 3]);
});

it('seeds tj india status devices with canonicalized shared channel ids', function (): void {
    seed(TJIndiaStatusSeeder::class);

    $stenter08Status = Device::query()->where('name', 'IN-TJ-Stenter08  Status')->firstOrFail();
    $stenter06Status = Device::query()->where('name', 'IN-TJ-Stenter06 Status')->firstOrFail();
    $compactor02Status = Device::query()->where('name', 'IN-TJ-Compactor02 Status')->firstOrFail();

    expect($stenter08Status->external_id)->toBe('869604063844418-00-4')
        ->and($stenter06Status->external_id)->toBe('869604063798168-00-7')
        ->and($compactor02Status->external_id)->toBe('869604063798168-00-5');
});

it('seeds tj india production lines with linked physical devices', function (): void {
    seed([
        TJIndiaFabricLengthSeeder::class,
        TJIndiaStatusSeeder::class,
        TJIndiaStenterSeeder::class,
    ]);

    $compactor01 = Device::query()->where('external_id', 'tj-india-compactor01')->firstOrFail();
    $stenter11 = Device::query()->where('external_id', 'tj-india-stenter11')->firstOrFail();

    $compactor01->load('virtualDeviceLinks.sourceDevice');
    $stenter11->load('virtualDeviceLinks.sourceDevice');

    expect($compactor01->virtualDeviceLinks)->toHaveCount(2)
        ->and($compactor01->virtualDeviceLinks->pluck('purpose')->all())->toEqualCanonicalizing(['length', 'status'])
        ->and($compactor01->virtualDeviceLinks->pluck('sourceDevice.external_id')->all())->toEqualCanonicalizing([
            '869604063798168-52-2',
            '869604063798168-00-4',
        ])
        ->and($stenter11->virtualDeviceLinks->pluck('sourceDevice.external_id')->all())->toEqualCanonicalizing([
            '869604063844418-51-1',
            '869604063844418-00-1',
        ]);
});
