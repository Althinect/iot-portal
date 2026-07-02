<?php

declare(strict_types=1);

use App\Domain\DataIngestion\Models\DeviceSignalBinding;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use App\Domain\Shared\Models\User;
use App\Filament\Admin\Resources\DeviceManagement\Devices\Pages\ViewDevice;
use App\Filament\Admin\Resources\DeviceManagement\Devices\RelationManagers\SignalBindingsRelationManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Auth::login(User::factory()->create(['is_super_admin' => true]));
});

it('creates signal bindings scoped to the device profile version channel parameters', function (): void {
    $profileVersion = DeviceProfileVersion::factory()->active()->mqtt()->create();
    $channel = DeviceChannel::factory()->telemetry()->create([
        'device_profile_version_id' => $profileVersion->id,
        'key' => 'telemetry',
        'label' => 'Telemetry',
    ]);
    ProfileParameterDefinition::factory()->create([
        'device_channel_id' => $channel->id,
        'key' => 'temperature',
        'label' => 'Temperature',
        'json_path' => 'temperature',
        'type' => ParameterDataType::Decimal,
        'is_active' => true,
    ]);
    $device = Device::factory()->create([
        'device_profile_version_id' => $profileVersion->id,
    ]);

    livewire(SignalBindingsRelationManager::class, [
        'ownerRecord' => $device,
        'pageClass' => ViewDevice::class,
    ])
        ->callTableAction('create', data: [
            'device_channel_id' => $channel->id,
            'parameter_key' => 'temperature',
            'source_topic' => 'migration/source/imoni/123/00/telemetry',
            'source_json_path' => '$.io_1_value',
            'source_adapter' => 'imoni',
            'sequence' => 0,
            'is_active' => true,
            'metadata' => [],
        ])
        ->assertHasNoTableActionErrors();

    $this->assertDatabaseHas('device_signal_bindings', [
        'device_id' => $device->id,
        'device_channel_id' => $channel->id,
        'parameter_key' => 'temperature',
        'source_topic' => 'migration/source/imoni/123/00/telemetry',
    ]);
});

it('rejects signal bindings for channels outside the device profile version', function (): void {
    $profileVersion = DeviceProfileVersion::factory()->active()->mqtt()->create();
    $otherProfileVersion = DeviceProfileVersion::factory()->active()->mqtt()->create();
    $otherChannel = DeviceChannel::factory()->telemetry()->create([
        'device_profile_version_id' => $otherProfileVersion->id,
    ]);
    ProfileParameterDefinition::factory()->create([
        'device_channel_id' => $otherChannel->id,
        'key' => 'temperature',
        'type' => ParameterDataType::Decimal,
        'is_active' => true,
    ]);
    $device = Device::factory()->create([
        'device_profile_version_id' => $profileVersion->id,
    ]);

    livewire(SignalBindingsRelationManager::class, [
        'ownerRecord' => $device,
        'pageClass' => ViewDevice::class,
    ])
        ->callTableAction('create', data: [
            'device_channel_id' => $otherChannel->id,
            'parameter_key' => 'temperature',
            'source_topic' => 'migration/source/imoni/123/00/telemetry',
            'source_json_path' => '$.io_1_value',
            'is_active' => true,
        ])
        ->assertHasTableActionErrors(['device_channel_id']);

    expect(DeviceSignalBinding::query()->count())->toBe(0);
});
