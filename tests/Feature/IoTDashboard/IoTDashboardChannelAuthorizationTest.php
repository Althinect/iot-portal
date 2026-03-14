<?php

declare(strict_types=1);

use App\Broadcasting\IoTDashboardDeviceTopicChannel;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Domain\Shared\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('authorizes private device-topic telemetry channels for organization members', function (): void {
    $schemaVersion = DeviceSchemaVersion::factory()->create();
    $device = Device::factory()->create([
        'device_schema_version_id' => $schemaVersion->id,
    ]);
    $user = User::factory()->create(['is_super_admin' => false]);
    $user->organizations()->attach($device->organization_id);

    $channel = new IoTDashboardDeviceTopicChannel;

    expect($channel->join($user, $device->uuid, 42))->toBeTrue();
});

it('denies private device-topic telemetry channels for non-members', function (): void {
    $schemaVersion = DeviceSchemaVersion::factory()->create();
    $device = Device::factory()->create([
        'device_schema_version_id' => $schemaVersion->id,
    ]);
    $user = User::factory()->create(['is_super_admin' => false]);

    $channel = new IoTDashboardDeviceTopicChannel;

    expect($channel->join($user, $device->uuid, 42))->toBeFalse();
});
