<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Models\DeviceType;
use App\Domain\DeviceSchema\Models\DeviceSchema;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Domain\Shared\Models\User;
use App\Filament\Admin\Resources\DeviceManagement\Devices\Pages\ViewDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($this->user);
});

it('can render the view device page', function (): void {
    $device = Device::factory()->create();

    livewire(ViewDevice::class, ['record' => $device->id])
        ->assertSuccessful();
});

it('can render nested metadata on the view device page', function (): void {
    $device = Device::factory()->create([
        'metadata' => [
            'migration_tenant' => 'witco',
            'legacy_parameter_mappings' => [
                'status' => 'peripheralDataArr.iMoni_LITE.2.3',
            ],
        ],
    ]);

    livewire(ViewDevice::class, ['record' => $device->id])
        ->assertSuccessful();
});

it('shows the firmware viewer action on the device view page', function (): void {
    $deviceType = DeviceType::factory()->mqtt()->create();
    $schema = DeviceSchema::factory()->forDeviceType($deviceType)->create();
    $schemaVersion = DeviceSchemaVersion::factory()->create([
        'device_schema_id' => $schema->id,
        'firmware_template' => 'const char* DEVICE_ID = "{{DEVICE_ID}}";',
    ]);

    $device = Device::factory()->create([
        'device_type_id' => $deviceType->id,
        'device_schema_version_id' => $schemaVersion->id,
        'external_id' => 'device-101',
    ]);

    livewire(ViewDevice::class, ['record' => $device->id])
        ->assertActionExists('viewFirmware')
        ->assertActionExists('provisionX509')
        ->assertActionHidden('controlDashboard');
});

it('can open firmware modal and see rendered firmware', function (): void {
    $deviceType = DeviceType::factory()->mqtt()->create();
    $schema = DeviceSchema::factory()->forDeviceType($deviceType)->create();
    $schemaVersion = DeviceSchemaVersion::factory()->create([
        'device_schema_id' => $schema->id,
        'firmware_template' => 'const char* DEVICE_ID = "{{DEVICE_ID}}";',
    ]);

    $device = Device::factory()->create([
        'device_type_id' => $deviceType->id,
        'device_schema_version_id' => $schemaVersion->id,
        'external_id' => 'device-xyz',
    ]);

    livewire(ViewDevice::class, ['record' => $device->id])
        ->mountAction('viewFirmware')
        ->assertActionMounted('viewFirmware')
        ->assertActionDataSet(function (array $data): bool {
            $firmware = $data['firmware'] ?? null;

            return is_string($firmware) && str_contains($firmware, 'const char* DEVICE_ID = "device-xyz";');
        });
});

it('shows control dashboard action when the device has command topics', function (): void {
    $deviceType = DeviceType::factory()->mqtt()->create();
    $schema = DeviceSchema::factory()->forDeviceType($deviceType)->create();
    $schemaVersion = DeviceSchemaVersion::factory()->create([
        'device_schema_id' => $schema->id,
    ]);

    SchemaVersionTopic::factory()->subscribe()->create([
        'device_schema_version_id' => $schemaVersion->id,
    ]);

    $device = Device::factory()->create([
        'device_type_id' => $deviceType->id,
        'device_schema_version_id' => $schemaVersion->id,
    ]);

    livewire(ViewDevice::class, ['record' => $device->id])
        ->assertActionVisible('controlDashboard');
});
