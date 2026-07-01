<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Jobs\SimulateDevicePublishingJob;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\DeviceProfile;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\Shared\Models\User;
use App\Filament\Admin\Resources\DeviceManagement\Devices\Pages\ListDevices;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($this->admin);
});

it('queues simulation jobs for selected devices', function (): void {
    Queue::fake();

    $profileVersion = DeviceProfileVersion::factory()->active()->mqtt()->create();

    DeviceChannel::factory()->telemetry()->create([
        'device_profile_version_id' => $profileVersion->id,
        'address' => 'telemetry',
    ]);

    $devices = Device::factory()->count(2)->create([
        'device_profile_version_id' => $profileVersion->id,
    ]);

    livewire(ListDevices::class)
        ->selectTableRecords($devices->pluck('id')->all())
        ->callAction(TestAction::make('simulatePublishingBulk')->table()->bulk(), data: [
            'count' => 5,
            'interval' => 1,
        ]);

    Queue::assertPushedTimes(SimulateDevicePublishingJob::class, 10);
    Queue::assertPushed(SimulateDevicePublishingJob::class, fn (SimulateDevicePublishingJob $job): bool => $job->count === 1 && $job->intervalSeconds === 0);
});

it('can replicate a device from the list table', function (): void {
    $profile = DeviceProfile::factory()->global()->create();
    $profileVersion = DeviceProfileVersion::factory()->forProfile($profile)->active()->mqtt()->create();

    $device = Device::factory()->create([
        'device_profile_version_id' => $profileVersion->id,
        'name' => 'Pump Controller',
        'external_id' => 'pump-01',
        'is_active' => true,
        'connection_state' => 'online',
    ]);

    livewire(ListDevices::class)
        ->callTableAction('replicate', $device, data: [
            'name' => 'Pump Controller Copy',
            'external_id' => null,
            'organization_id' => $device->organization_id,
            'device_profile_version_id' => $device->device_profile_version_id,
            'is_active' => false,
        ])
        ->assertHasNoFormErrors();

    $replica = Device::query()
        ->where('id', '!=', $device->id)
        ->latest('id')
        ->first();

    expect($replica)->not->toBeNull()
        ->and($replica?->name)->toBe('Pump Controller Copy')
        ->and($replica?->external_id)->toBeNull()
        ->and($replica?->is_active)->toBeFalse()
        ->and($replica?->connection_state)->toBeNull();
});

it('allows overriding fields when replicating a device from the modal form', function (): void {
    $profile = DeviceProfile::factory()->global()->create();
    $profileVersion = DeviceProfileVersion::factory()->forProfile($profile)->active()->mqtt()->create();

    $device = Device::factory()->create([
        'device_profile_version_id' => $profileVersion->id,
        'name' => 'Fan Controller',
        'external_id' => 'fan-01',
        'is_active' => true,
    ]);

    livewire(ListDevices::class)
        ->callTableAction('replicate', $device, data: [
            'name' => 'Fan Controller Clone A',
            'external_id' => 'fan-01-clone-a',
            'organization_id' => $device->organization_id,
            'device_profile_version_id' => $device->device_profile_version_id,
            'is_active' => true,
        ])
        ->assertHasNoFormErrors();

    $replica = Device::query()
        ->where('id', '!=', $device->id)
        ->latest('id')
        ->first();

    expect($replica)->not->toBeNull()
        ->and($replica?->name)->toBe('Fan Controller Clone A')
        ->and($replica?->external_id)->toBe('fan-01-clone-a')
        ->and($replica?->is_active)->toBeTrue()
        ->and($replica?->device_profile_version_id)->toBe($device->device_profile_version_id);
});
