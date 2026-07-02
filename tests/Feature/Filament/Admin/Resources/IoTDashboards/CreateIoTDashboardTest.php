<?php

declare(strict_types=1);

use App\Domain\IoTDashboard\Enums\DashboardHistoryPreset;
use App\Domain\IoTDashboard\Models\IoTDashboard;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use App\Filament\Admin\Resources\IoTDashboards\Pages\CreateIoTDashboard;
use App\Filament\Admin\Resources\IoTDashboards\Pages\ViewIoTDashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $admin = User::factory()->create(['is_super_admin' => true]);

    $this->actingAs($admin);
});

it('renders the dashboard create page', function (): void {
    livewire(CreateIoTDashboard::class)
        ->assertSuccessful();
});

it('creates a dashboard', function (): void {
    $organization = Organization::factory()->create();

    livewire(CreateIoTDashboard::class)
        ->fillForm([
            'organization_id' => $organization->id,
            'name' => 'Factory Floor',
            'slug' => 'factory-floor',
            'description' => 'Production floor telemetry dashboard.',
            'refresh_interval_seconds' => 15,
            'default_history_preset' => DashboardHistoryPreset::Last12Hours->value,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $this->assertDatabaseHas('iot_dashboards', [
        'organization_id' => $organization->id,
        'name' => 'Factory Floor',
        'slug' => 'factory-floor',
        'description' => 'Production floor telemetry dashboard.',
        'refresh_interval_seconds' => 15,
        'default_history_preset' => DashboardHistoryPreset::Last12Hours->value,
        'is_active' => true,
    ]);

    $dashboard = IoTDashboard::query()
        ->where('organization_id', $organization->id)
        ->where('slug', 'factory-floor')
        ->sole();

    livewire(ViewIoTDashboard::class, ['record' => $dashboard->getRouteKey()])
        ->assertSuccessful();
});
