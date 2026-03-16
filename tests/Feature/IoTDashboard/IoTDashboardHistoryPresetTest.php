<?php

declare(strict_types=1);

use App\Domain\IoTDashboard\Enums\DashboardHistoryPreset;
use App\Domain\IoTDashboard\Models\IoTDashboard;
use App\Domain\Shared\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($admin);
});

it('defaults dashboards to a six hour history preset', function (): void {
    $dashboard = IoTDashboard::factory()->create();

    expect($dashboard->default_history_preset)
        ->toBe(DashboardHistoryPreset::Last6Hours);
});

it('bootstraps the configured dashboard history preset on the dashboard page', function (): void {
    $dashboard = IoTDashboard::factory()->create([
        'default_history_preset' => DashboardHistoryPreset::Last12Hours,
    ]);

    $this->get(route('filament.admin.pages.io-t-dashboard', ['dashboard' => $dashboard->id]))
        ->assertSuccessful()
        ->assertSee('default_history_preset', escape: false)
        ->assertSee('12h', escape: false);
});
