<?php

declare(strict_types=1);

use App\Domain\DataIngestion\Models\DeviceSignalBinding;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\Shared\Models\Organization;
use Database\Seeders\CommercialBankMigrationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

it('seeds the commercial bank legacy inventory idempotently', function (): void {
    seed(CommercialBankMigrationSeeder::class);
    seed(CommercialBankMigrationSeeder::class);

    /** @var Organization $organization */
    $organization = Organization::query()
        ->where('slug', 'commercial-bank')
        ->firstOrFail();

    $devices = Device::query()
        ->where('organization_id', $organization->id)
        ->get();

    $countsByMigrationType = $devices
        ->groupBy(fn (Device $device): string => (string) ($device->metadata['migration_device_type'] ?? 'unknown'))
        ->map(fn ($groupedDevices): int => $groupedDevices->count())
        ->all();

    expect($organization->name)->toBe('Commercial Bank')
        ->and($devices)->toHaveCount(25)
        ->and($countsByMigrationType)->toMatchArray([
            'IMoni Hub' => 7,
            'TH Sensor' => 10,
            'IMoni Lite' => 6,
            'AC Energy Mate' => 2,
        ])
        ->and(DeviceSignalBinding::query()->count())->toBe(68)
        ->and(Device::query()->where('external_id', '869244041748215')->exists())->toBeTrue()
        ->and(Device::query()->where('external_id', '869244041759659-21')->exists())->toBeTrue()
        ->and(Device::query()->where('external_id', '169244041767793-00')->exists())->toBeTrue();
});

it('preserves cross-tenant bank topology from the legacy source', function (): void {
    seed(CommercialBankMigrationSeeder::class);

    /** @var Device $bankHub */
    $bankHub = Device::query()
        ->where('external_id', '169244041767793')
        ->firstOrFail();

    /** @var Device $temperatureSensor */
    $temperatureSensor = Device::query()
        ->where('external_id', '169244041767793-81')
        ->firstOrFail();

    /** @var Device $hubStatus */
    $hubStatus = Device::query()
        ->where('external_id', '169244041767793-00')
        ->firstOrFail();

    expect($bankHub->metadata)->toMatchArray([
        'migration_origin' => 'commercial-bank',
        'migration_device_type' => 'IMoni Hub',
        'legacy_source_organization_id' => 14,
        'legacy_source_organization_name' => 'Ncinga',
    ])
        ->and($temperatureSensor->parent_device_id)->toBe($bankHub->id)
        ->and($temperatureSensor->metadata)->toMatchArray([
            'legacy_parent_source_organization_id' => 14,
            'legacy_parent_source_organization_name' => 'Ncinga',
        ])
        ->and($hubStatus->parent_device_id)->toBe($bankHub->id)
        ->and($hubStatus->metadata)->toMatchArray([
            'legacy_source_organization_id' => 14,
            'legacy_source_organization_name' => 'Ncinga',
            'legacy_parent_source_organization_id' => 14,
            'legacy_parent_source_organization_name' => 'Ncinga',
        ]);
});

it('creates calibrated signal bindings for climate, iMoni Lite, and energy devices', function (): void {
    seed(CommercialBankMigrationSeeder::class);

    /** @var Device $temperatureSensor */
    $temperatureSensor = Device::query()
        ->where('external_id', '169244041767793-81')
        ->firstOrFail();

    /** @var Device $vaultDoor */
    $vaultDoor = Device::query()
        ->where('external_id', '169244041760699-00')
        ->firstOrFail();

    /** @var Device $lightDb */
    $lightDb = Device::query()
        ->where('external_id', '869244041759659-21')
        ->firstOrFail();

    /** @var DeviceSignalBinding $temperatureBinding */
    $temperatureBinding = DeviceSignalBinding::query()
        ->where('device_id', $temperatureSensor->id)
        ->where('parameter_key', 'temperature')
        ->firstOrFail();

    /** @var DeviceSignalBinding $vaultDoorBinding */
    $vaultDoorBinding = DeviceSignalBinding::query()
        ->where('device_id', $vaultDoor->id)
        ->where('parameter_key', 'ioid1')
        ->firstOrFail();

    /** @var DeviceSignalBinding $energyBinding */
    $energyBinding = DeviceSignalBinding::query()
        ->where('device_id', $lightDb->id)
        ->where('parameter_key', 'PhaseAVoltage')
        ->firstOrFail();

    expect($temperatureBinding->source_topic)->toBe('migration/source/imoni/169244041767793/81/telemetry')
        ->and($temperatureBinding->source_json_path)->toBe('$.io_2_value')
        ->and($temperatureBinding->metadata['legacy_source_path'] ?? null)->toBe('peripheralDataArr.THsensor1.2.3')
        ->and($vaultDoorBinding->source_topic)->toBe('migration/source/imoni/169244041760699/00/telemetry')
        ->and($vaultDoorBinding->source_json_path)->toBe('$.io_2_value')
        ->and($lightDb->profileVersion?->version)->toBe(20)
        ->and($energyBinding->source_topic)->toBe('migration/source/imoni/869244041759659/21/telemetry')
        ->and($energyBinding->source_json_path)->toBe('$.io_1_value')
        ->and($energyBinding->metadata['mutation_expression'] ?? null)->toBe([
            '/' => [
                ['var' => 'val'],
                10,
            ],
        ]);
});
