<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Models\TemporaryDevice;
use App\Domain\DeviceProfile\Models\DeviceProfile;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use App\Filament\Admin\Resources\DeviceManagement\Devices\Pages\ListDevices;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\assertSoftDeleted;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($this->admin);
});

function createTemporaryDevicesListFixture(bool $temporary): Device
{
    $organization = Organization::factory()->create();
    $profile = DeviceProfile::factory()->create(['organization_id' => $organization->id]);
    $profileVersion = DeviceProfileVersion::factory()->forProfile($profile)->active()->mqtt()->create();

    $device = Device::factory()->create([
        'organization_id' => $organization->id,
        'device_profile_version_id' => $profileVersion->id,
    ]);

    if ($temporary) {
        TemporaryDevice::factory()->for($device, 'device')->create();
    }

    return $device;
}

it('filters temporary devices and allows deleting the filtered records', function (): void {
    $temporaryDevice = createTemporaryDevicesListFixture(true);
    $permanentDevice = createTemporaryDevicesListFixture(false);

    livewire(ListDevices::class)
        ->assertCanSeeTableRecords([$temporaryDevice, $permanentDevice])
        ->filterTable('temporary_devices')
        ->assertCanSeeTableRecords([$temporaryDevice])
        ->assertCanNotSeeTableRecords([$permanentDevice])
        ->selectTableRecords([$temporaryDevice->id])
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk())
        ->assertNotified();

    assertSoftDeleted('devices', ['id' => $temporaryDevice->id]);

    expect(Device::query()->find($permanentDevice->id))->not->toBeNull();
});
