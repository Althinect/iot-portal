<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\Shared\Models\User;
use App\Filament\Admin\Resources\DeviceManagement\Devices\Pages\ListDevices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($this->admin);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('shows the effective offline state for stale devices even before the sweep rewrites the stored status', function (): void {
    $now = Carbon::parse('2026-03-05 12:00:00');

    Carbon::setTestNow($now);

    $staleDevice = Device::factory()->create([
        'connection_state' => 'online',
        'last_seen_at' => $now->copy()->subHours(2),
        'offline_deadline_at' => $now->copy()->subMinute(),
    ]);

    livewire(ListDevices::class)
        ->assertSuccessful()
        ->assertTableColumnStateSet('effective_connection_state', 'offline', $staleDevice)
        ->assertTableColumnStateNotSet('effective_connection_state', 'online', $staleDevice);
});

it('filters devices by their effective offline state', function (): void {
    $now = Carbon::parse('2026-03-05 12:00:00');

    Carbon::setTestNow($now);

    $onlineDevice = Device::factory()->create([
        'connection_state' => 'online',
        'last_seen_at' => $now->copy()->subSeconds(30),
        'offline_deadline_at' => $now->copy()->addMinutes(4),
    ]);

    $staleDevice = Device::factory()->create([
        'connection_state' => 'online',
        'last_seen_at' => $now->copy()->subHours(3),
        'offline_deadline_at' => $now->copy()->subMinute(),
    ]);

    $offlineDevice = Device::factory()->create([
        'connection_state' => 'offline',
        'last_seen_at' => $now->copy()->subMinutes(5),
        'offline_deadline_at' => null,
    ]);

    livewire(ListDevices::class)
        ->filterTable('effective_connection_state', 'offline')
        ->assertCanSeeTableRecords([$staleDevice, $offlineDevice])
        ->assertCanNotSeeTableRecords([$onlineDevice]);
});

it('filters devices by their effective online state', function (): void {
    $now = Carbon::parse('2026-03-05 12:00:00');

    Carbon::setTestNow($now);

    $onlineDevice = Device::factory()->create([
        'connection_state' => 'online',
        'last_seen_at' => $now->copy()->subSeconds(45),
        'offline_deadline_at' => $now->copy()->addMinutes(4),
    ]);

    $staleDevice = Device::factory()->create([
        'connection_state' => 'online',
        'last_seen_at' => $now->copy()->subHours(3),
        'offline_deadline_at' => $now->copy()->subMinute(),
    ]);

    livewire(ListDevices::class)
        ->filterTable('effective_connection_state', 'online')
        ->assertCanSeeTableRecords([$onlineDevice])
        ->assertCanNotSeeTableRecords([$staleDevice]);
});
