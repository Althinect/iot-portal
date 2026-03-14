<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Models\DeviceType;
use App\Domain\DeviceManagement\Models\TemporaryDevice;
use App\Domain\DeviceSchema\Models\DeviceSchema;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Domain\Shared\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-03-08 12:00:00'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function createTemporaryDevicePurgeDeviceFixture(): Device
{
    $organization = Organization::factory()->create();
    $deviceType = DeviceType::factory()->forOrganization($organization->id)->mqtt()->create();
    $schema = DeviceSchema::factory()->forDeviceType($deviceType)->create();
    $schemaVersion = DeviceSchemaVersion::factory()->create([
        'device_schema_id' => $schema->id,
        'status' => 'active',
    ]);

    return Device::factory()->create([
        'organization_id' => $organization->id,
        'device_type_id' => $deviceType->id,
        'device_schema_version_id' => $schemaVersion->id,
    ]);
}

it('purges expired temporary devices and leaves unexpired and permanent devices intact', function (): void {
    $expiredDevice = createTemporaryDevicePurgeDeviceFixture();
    TemporaryDevice::factory()->for($expiredDevice, 'device')->create([
        'expires_at' => now()->subHour(),
    ]);

    $softDeletedExpiredDevice = createTemporaryDevicePurgeDeviceFixture();
    TemporaryDevice::factory()->for($softDeletedExpiredDevice, 'device')->create([
        'expires_at' => now()->subMinutes(30),
    ]);
    $softDeletedExpiredDevice->delete();

    $unexpiredDevice = createTemporaryDevicePurgeDeviceFixture();
    TemporaryDevice::factory()->for($unexpiredDevice, 'device')->create([
        'expires_at' => now()->addHour(),
    ]);

    $permanentDevice = createTemporaryDevicePurgeDeviceFixture();

    $this->artisan('iot:purge-temporary-devices')
        ->expectsOutput('Purged 2 expired temporary device(s).')
        ->assertSuccessful();

    expect(Device::withTrashed()->find($expiredDevice->id))->toBeNull()
        ->and(Device::withTrashed()->find($softDeletedExpiredDevice->id))->toBeNull()
        ->and(Device::withTrashed()->find($unexpiredDevice->id))->not->toBeNull()
        ->and(Device::withTrashed()->find($permanentDevice->id))->not->toBeNull()
        ->and(TemporaryDevice::query()->where('device_id', $expiredDevice->id)->exists())->toBeFalse()
        ->and(TemporaryDevice::query()->where('device_id', $softDeletedExpiredDevice->id)->exists())->toBeFalse()
        ->and(TemporaryDevice::query()->where('device_id', $unexpiredDevice->id)->exists())->toBeTrue();
});
