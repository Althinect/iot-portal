<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Domain\Shared\Models\User;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use App\Filament\Admin\Resources\DeviceManagement\Devices\Pages\ViewDevice;
use App\Filament\Admin\Resources\DeviceManagement\Devices\RelationManagers\TelemetryLogsRelationManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($this->user);

    TelemetryLogsRelationManager::skipAuthorization();

    $this->device = Device::factory()->create();
});

afterEach(function (): void {
    TelemetryLogsRelationManager::skipAuthorization(false);
});

it('can render the telemetry logs relation manager on the view device page', function (): void {
    livewire(TelemetryLogsRelationManager::class, [
        'ownerRecord' => $this->device,
        'pageClass' => ViewDevice::class,
    ])->assertOk();
});

it('shows telemetry values in the modal without the device uuid column', function (): void {
    $topic = SchemaVersionTopic::factory()->publish()->create([
        'device_schema_version_id' => $this->device->device_schema_version_id,
    ]);

    $telemetryLog = DeviceTelemetryLog::factory()
        ->forDevice($this->device)
        ->forTopic($topic)
        ->create([
            'raw_payload' => [
                'temperature' => 24.5,
                'humidity' => 63,
            ],
            'transformed_values' => [
                'temperature' => 24.5,
                'humidity' => 63,
                'status' => 'ok',
            ],
        ]);

    livewire(TelemetryLogsRelationManager::class, [
        'ownerRecord' => $this->device,
        'pageClass' => ViewDevice::class,
    ])
        ->assertCanSeeTableRecords([$telemetryLog])
        ->assertDontSee('Device UUID')
        ->mountTableAction('view', $telemetryLog)
        ->assertSee('Values')
        ->assertTableActionDataSet(fn (array $data): bool => ($data['transformed_values']['temperature'] ?? null) === 24.5
            && ($data['transformed_values']['humidity'] ?? null) === 63
            && ($data['transformed_values']['status'] ?? null) === 'ok');
});
