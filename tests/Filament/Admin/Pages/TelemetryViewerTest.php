<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Domain\Shared\Models\User;
use App\Filament\Admin\Pages\TelemetryViewer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($this->admin);
});

it('can render the telemetry viewer page', function (): void {
    livewire(TelemetryViewer::class)
        ->assertSuccessful();
});

it('shows the live telemetry viewer content', function (): void {
    config()->set('iot.broadcast.raw_telemetry', false);

    livewire(TelemetryViewer::class)
        ->assertSee('Diagnostics Disabled')
        ->assertSee('Raw telemetry broadcasting is disabled by default');
});

it('shows the live telemetry stream when diagnostics are explicitly enabled', function (): void {
    config()->set('iot.broadcast.raw_telemetry', true);

    livewire(TelemetryViewer::class)
        ->assertSee('Pre-Ingestion Stream');
});

it('renders the enabled stream for external device ids from the query string', function (): void {
    config()->set('iot.broadcast.raw_telemetry', true);

    $this->get('/admin/telemetry-viewer?device=869604063866247-28&topic=telemetry')
        ->assertOk()
        ->assertSee('Pre-Ingestion Stream')
        ->assertDontSee('Diagnostics Disabled');
});

it('uses runtime websocket host resolution for raw telemetry diagnostics', function (): void {
    config()->set('iot.broadcast.raw_telemetry', true);

    $this->get('/admin/telemetry-viewer?device=869604063866247-28&topic=telemetry')
        ->assertOk()
        ->assertSee("window.Echo.channel('telemetry')", false)
        ->assertSee('configuredForceTls', false)
        ->assertDontSee("runtimeHost.endsWith('.test')", false)
        ->assertDontSee('URLSearchParams(window.location.search)', false)
        ->assertSee('wsHost,', false);
});

it('appends matching raw telemetry events in the live stream', function (): void {
    config()->set('iot.broadcast.raw_telemetry', true);

    $schemaVersion = DeviceSchemaVersion::factory()->create();
    $topic = SchemaVersionTopic::factory()->publish()->create([
        'device_schema_version_id' => $schemaVersion->id,
        'suffix' => 'telemetry',
        'label' => 'Telemetry',
    ]);
    $device = Device::factory()->create([
        'device_schema_version_id' => $schemaVersion->id,
        'external_id' => '869604063866247-28',
    ]);

    livewire('admin.telemetry-live-stream')
        ->set('selectedDevice', '869604063866247-28')
        ->assertSet('selectedTopicSuffix', 'telemetry')
        ->dispatch('telemetryIncoming', entry: [
            'topic' => $topic->resolvedTopic($device),
            'device_uuid' => $device->uuid,
            'device_external_id' => '869604063866247-28',
            'payload' => ['temperature' => 26.4],
            'received_at' => now()->toIso8601String(),
        ])
        ->assertSet('messageCount', 1)
        ->assertSee('26.4');
});

it('does not error when device query string is not a uuid', function (): void {
    config()->set('iot.broadcast.raw_telemetry', false);

    $this->get('/admin/telemetry-viewer?device=1234567')
        ->assertOk()
        ->assertSee('Diagnostics Disabled');
});
