<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Enums\HttpAuthType;
use App\Domain\DeviceManagement\Enums\ProtocolType;
use App\Domain\DeviceManagement\Models\DeviceType;
use App\Domain\DeviceManagement\ValueObjects\Protocol\HttpProtocolConfig;
use App\Domain\Shared\Models\User;
use App\Filament\Admin\Resources\DeviceManagement\DeviceTypes\Pages\EditDeviceType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($this->user);
});

it('can render the edit device type page', function (): void {
    $deviceType = DeviceType::factory()->mqtt()->create();

    livewire(EditDeviceType::class, ['record' => $deviceType->id])
        ->assertSuccessful();
});

it('can update a device type', function (): void {
    $deviceType = DeviceType::factory()->mqtt()->create(['name' => 'Old Name']);

    livewire(EditDeviceType::class, ['record' => $deviceType->id])
        ->fillForm([
            'name' => 'Updated Name',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $deviceType->refresh();
    expect($deviceType->name)->toBe('Updated Name');
});

it('can update MQTT protocol configuration', function (): void {
    $deviceType = DeviceType::factory()->mqtt()->create();

    livewire(EditDeviceType::class, ['record' => $deviceType->id])
        ->fillForm([
            'protocol_config.broker_host' => 'new-mqtt.example.com',
            'protocol_config.broker_port' => 1884,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $deviceType->refresh();
    expect($deviceType->protocol_config->brokerHost)->toBe('new-mqtt.example.com')
        ->and($deviceType->protocol_config->brokerPort)->toBe(1884);
});

it('can update HTTP protocol configuration', function (): void {
    $deviceType = DeviceType::factory()->create([
        'default_protocol' => ProtocolType::Http,
        'protocol_config' => (new HttpProtocolConfig(
            baseUrl: 'https://api.example.com',
            telemetryEndpoint: '/telemetry',
            method: 'POST',
            authType: HttpAuthType::None,
            timeout: 30,
        ))->toArray(),
    ]);

    livewire(EditDeviceType::class, ['record' => $deviceType->id])
        ->fillForm([
            'protocol_config.base_url' => 'https://new-api.example.com',
            'protocol_config.timeout' => 60,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $deviceType->refresh();
    expect($deviceType->protocol_config->baseUrl)->toBe('https://new-api.example.com')
        ->and($deviceType->protocol_config->timeout)->toBe(60);
});

it('validates unique key when editing', function (): void {
    $deviceType1 = DeviceType::factory()->global()->create(['key' => 'existing_key']);
    $deviceType2 = DeviceType::factory()->global()->create(['key' => 'another_key']);

    livewire(EditDeviceType::class, ['record' => $deviceType2->id])
        ->fillForm([
            'key' => 'existing_key',
        ])
        ->call('save')
        ->assertHasFormErrors(['key']);
});

it('can delete device type from edit page', function (): void {
    $deviceType = DeviceType::factory()->global()->create();

    livewire(EditDeviceType::class, ['record' => $deviceType->id])
        ->callAction('delete');

    $this->assertModelMissing($deviceType);
});
