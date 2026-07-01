<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Models\DeviceProfile;
use App\Domain\IoTDashboard\Enums\WidgetType;
use App\Domain\IoTDashboard\Models\IoTDashboard;
use App\Domain\IoTDashboard\Widgets\StenterUtilization\StenterUtilizationConfig;
use App\Domain\Shared\Models\Organization;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds the witco, miracle dome, textrip, commercial bank, tj india, teejay, and srilankan pilots plus the shared migration catalog by default', function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    $organizationSlugs = Organization::query()
        ->orderBy('slug', 'asc')
        ->pluck('slug')
        ->all();

    $profileKeys = DeviceProfile::query()
        ->orderBy('key', 'asc')
        ->pluck('key')
        ->all();

    $allDeviceExternalIds = Device::query()
        ->orderBy('external_id')
        ->pluck('external_id')
        ->all();

    $stenterStandard = Device::query()
        ->where('external_id', 'tj-stenter01-agr')
        ->first();

    $stenterStandard?->load('virtualDeviceLinks.sourceDevice');

    $stenterDashboard = IoTDashboard::query()
        ->with('widgets')
        ->where('slug', 'teejay-stenter-standards')
        ->first();

    $stenterWidgetTitles = $stenterDashboard?->widgets
        ->pluck('title')
        ->all() ?? [];
    $stenterWidgetTypes = $stenterDashboard?->widgets
        ->pluck('type')
        ->unique()
        ->values()
        ->all() ?? [];
    $stenterFirstWidget = $stenterDashboard?->widgets
        ->firstWhere('title', 'TJ - Stenter01  (AGR) · Utilization');

    $profileVersions = fn (string $profileKey): array => DeviceProfile::query()
        ->where('key', $profileKey)
        ->firstOrFail()
        ->versions()
        ->orderBy('version')
        ->pluck('version')
        ->map(static fn (mixed $version): int => (int) $version)
        ->all();

    expect($organizationSlugs)->toBe([
        'commercial-bank',
        'main-organization',
        'miracle-dome',
        'srilankan-airlines',
        'teejay',
        'textrip',
        'tj-india',
        'witco',
    ])->and($profileKeys)->toBe([
        'energy_meter',
        'fabric_length_counter',
        'legacy_climate_sensor',
        'legacy_egravity_sensor',
        'legacy_hub',
        'legacy_imoni_lite',
        'pressure_sensor',
        'status',
        'steam_meter',
        'stenter_line',
        'tank_level_sensor',
        'temperature_sensor',
        'water_flow_meter',
    ])->and(Organization::query()->where('slug', 'migration-rehearsal')->exists())->toBeFalse()
        ->and($allDeviceExternalIds)->not->toContain('869244049087921-00')
        ->and($allDeviceExternalIds)->not->toContain('869244049087921-11')
        ->and($allDeviceExternalIds)->not->toContain('869244049087921-12')
        ->and(Device::query()->where('external_id', '869244041754866-00-02')->exists())->toBeTrue()
        ->and(Device::query()->where('external_id', '869244041754866')->exists())->toBeTrue()
        ->and(Device::query()->where('external_id', '869244041759402')->exists())->toBeTrue()
        ->and(Device::query()->where('external_id', '869244041759402-22')->exists())->toBeTrue()
        ->and(Device::query()->where('external_id', '869244041759394-21')->exists())->toBeTrue()
        ->and(Device::query()->where('external_id', '869604063866064-51')->exists())->toBeTrue()
        ->and(Device::query()->where('external_id', '869244041754767')->exists())->toBeTrue()
        ->and(Device::query()->where('external_id', 'ea2b48f3-911f-4c90-88b7-29ac47799ed7')->exists())->toBeTrue()
        ->and(Device::query()->where('external_id', '869604063844418-52-2')->exists())->toBeTrue()
        ->and(Device::query()->where('external_id', 'tj-india-compactor01')->exists())->toBeTrue()
        ->and(Device::query()->where('external_id', '169244041767793')->exists())->toBeTrue()
        ->and(Device::query()->where('external_id', '169244041767793-00')->exists())->toBeTrue()
        ->and(Device::query()->where('external_id', '869244041759659-21')->exists())->toBeTrue()
        ->and($profileVersions('energy_meter'))->toBe([1, 2, 3, 4, 5, 20])
        ->and($profileVersions('tank_level_sensor'))->toBe([1, 2, 3, 4, 5, 6, 7])
        ->and($profileVersions('fabric_length_counter'))->toBe([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14])
        ->and($stenterStandard?->isVirtual())->toBeTrue()
        ->and($stenterStandard?->parent_device_id)->toBeNull()
        ->and($stenterStandard?->virtualDeviceLinks)->toHaveCount(3)
        ->and($stenterStandard?->virtualDeviceLinks->pluck('purpose')->all())->toEqualCanonicalizing(['energy', 'status', 'length'])
        ->and($stenterStandard?->virtualDeviceLinks->every(fn ($link): bool => $link->sourceDevice?->isVirtual() === false))->toBeTrue()
        ->and($stenterDashboard)->not->toBeNull()
        ->and($stenterWidgetTypes)->toBe([WidgetType::StenterUtilization->value])
        ->and($stenterWidgetTitles)->toContain('TJ - Stenter01  (AGR) · Utilization')
        ->and($stenterFirstWidget?->layoutArray())->toMatchArray([
            'w' => 4,
            'h' => 8,
            'card_height_px' => 768,
        ])
        ->and(data_get($stenterFirstWidget?->configArray(), 'percentage_thresholds'))->toBe(StenterUtilizationConfig::defaultPercentageThresholds());
});
