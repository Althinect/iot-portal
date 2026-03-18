<?php

declare(strict_types=1);

use App\Domain\IoTDashboard\Models\IoTDashboard;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use Database\Seeders\WitcoDashboardSeeder;
use Database\Seeders\WitcoMigrationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds WITCO status and history dashboards with state widgets for migrated physical devices', function (): void {
    $this->seed(WitcoMigrationSeeder::class);
    $this->seed(WitcoDashboardSeeder::class);

    $statusDashboard = IoTDashboard::query()
        ->where('slug', 'witco-status-dashboard')
        ->first();
    $historyDashboard = IoTDashboard::query()
        ->where('slug', 'witco-history-dashboard')
        ->first();

    expect($statusDashboard)->not->toBeNull()
        ->and($historyDashboard)->not->toBeNull();

    $statusWidgets = IoTDashboardWidget::query()
        ->where('iot_dashboard_id', $statusDashboard?->id)
        ->orderBy('sequence')
        ->get();
    $historyWidgets = IoTDashboardWidget::query()
        ->where('iot_dashboard_id', $historyDashboard?->id)
        ->orderBy('sequence')
        ->get();

    expect($statusWidgets)->toHaveCount(9)
        ->and($historyWidgets)->toHaveCount(9)
        ->and($statusWidgets->every(fn (IoTDashboardWidget $widget): bool => $widget->type === 'state_card'))->toBeTrue()
        ->and($historyWidgets->every(fn (IoTDashboardWidget $widget): bool => $widget->type === 'state_timeline'))->toBeTrue()
        ->and($statusWidgets->pluck('device.external_id')->all())->toContain(
            '869244041759279-00-02',
            '869244041759279-00-01',
            '869244041767199-00-01',
        )
        ->and(data_get($statusWidgets->firstWhere('title', 'Main Door Status')?->configArray(), 'display_style'))->toBe('pill')
        ->and(data_get($statusWidgets->firstWhere('title', 'Main Door Status')?->configArray(), 'state_mappings.0.label'))->toBe('OPEN')
        ->and(data_get($historyWidgets->firstWhere('title', 'Main Door Status')?->configArray(), 'state_mappings.1.label'))->toBe('CLOSED')
        ->and(data_get($historyWidgets->firstWhere('title', 'Main Door Status')?->layoutArray(), 'w'))->toBe(12);
});
