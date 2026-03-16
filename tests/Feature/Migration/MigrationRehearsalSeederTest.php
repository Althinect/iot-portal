<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Models\DeviceType;
use App\Domain\Shared\Models\Organization;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds only the witco pilot and global migration catalog by default', function (): void {
    $this->seed(DatabaseSeeder::class);

    $organizationSlugs = Organization::query()
        ->orderBy('slug')
        ->pluck('slug')
        ->all();

    $deviceTypeKeys = DeviceType::query()
        ->orderBy('key')
        ->pluck('key')
        ->all();

    $allDeviceExternalIds = Device::query()
        ->orderBy('external_id')
        ->pluck('external_id')
        ->all();

    expect($organizationSlugs)->toBe([
        'main-organization',
        'witco',
    ])->and($deviceTypeKeys)->toBe([
        'imoni_status',
        'legacy_hub',
    ])->and(Organization::query()->where('slug', 'migration-rehearsal')->exists())->toBeFalse()
        ->and($allDeviceExternalIds)->not->toContain('869244049087921-00')
        ->and($allDeviceExternalIds)->not->toContain('869244049087921-11')
        ->and($allDeviceExternalIds)->not->toContain('869244049087921-12')
        ->and(Device::query()->where('external_id', 'witco-water-tank-alarm-level')->exists())->toBeTrue()
        ->and(Device::query()->where('external_id', '869244041754866')->exists())->toBeTrue();
});
