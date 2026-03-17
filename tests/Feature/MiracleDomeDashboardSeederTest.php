<?php

declare(strict_types=1);

use App\Domain\IoTDashboard\Models\IoTDashboard;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use Database\Seeders\MiracleDomeDashboardSeeder;
use Database\Seeders\MiracleDomeMigrationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds miracle dome realtime and history dashboards for the migrated energy meters', function (): void {
    $this->seed(MiracleDomeMigrationSeeder::class);
    $this->seed(MiracleDomeDashboardSeeder::class);

    $energyDashboard = IoTDashboard::query()
        ->where('slug', 'miracle-dome-energy-dashboard')
        ->first();
    $historyDashboard = IoTDashboard::query()
        ->where('slug', 'miracle-dome-energy-history-dashboard')
        ->first();

    expect($energyDashboard)->not->toBeNull()
        ->and($historyDashboard)->not->toBeNull();

    $energyWidgets = IoTDashboardWidget::query()
        ->where('iot_dashboard_id', $energyDashboard?->id)
        ->orderBy('sequence')
        ->get();
    $historyWidgets = IoTDashboardWidget::query()
        ->where('iot_dashboard_id', $historyDashboard?->id)
        ->orderBy('sequence')
        ->get();

    $statusWidgets = $energyWidgets->where('type', 'status_summary')->values();
    $trendWidgets = $energyWidgets->where('type', 'line_chart')->values();

    expect($energyWidgets)->toHaveCount(6)
        ->and($historyWidgets)->toHaveCount(3)
        ->and($statusWidgets)->toHaveCount(3)
        ->and($trendWidgets)->toHaveCount(3)
        ->and($statusWidgets->every(fn (IoTDashboardWidget $widget): bool => $widget->type === 'status_summary'))->toBeTrue()
        ->and($trendWidgets->every(fn (IoTDashboardWidget $widget): bool => $widget->type === 'line_chart'))->toBeTrue()
        ->and($historyWidgets->every(fn (IoTDashboardWidget $widget): bool => $widget->type === 'bar_chart'))->toBeTrue()
        ->and($energyWidgets->pluck('device.external_id')->all())->toContain(
            'miracle-dome-video-room-2-energy-meter',
            'miracle-dome-server-room-2-energy-meter',
            'miracle-dome-bts-energy-meter',
        )
        ->and(data_get($statusWidgets->firstWhere('title', 'Video Room 2 Energy Meter Status')?->resolvedSeriesConfig(), '0.key'))->toBe('total_energy_kwh')
        ->and(data_get($statusWidgets->firstWhere('title', 'Video Room 2 Energy Meter Status')?->resolvedSeriesConfig(), '6.key'))->toBe('A3')
        ->and(data_get($statusWidgets->firstWhere('title', 'Video Room 2 Energy Meter Status')?->resolvedSeriesConfig(), '0.unit'))->toBe('kWh')
        ->and(data_get($statusWidgets->firstWhere('title', 'Video Room 2 Energy Meter Status')?->resolvedSeriesConfig(), '1.unit'))->toBe('Volts')
        ->and(data_get($statusWidgets->firstWhere('title', 'Video Room 2 Energy Meter Status')?->configArray(), 'rows.0.tiles.0.key'))->toBe('total_energy_kwh')
        ->and(data_get($statusWidgets->firstWhere('title', 'Video Room 2 Energy Meter Status')?->configArray(), 'rows.1.tiles.*.key'))->toBe(['V1', 'V2', 'V3'])
        ->and(data_get($statusWidgets->firstWhere('title', 'Video Room 2 Energy Meter Status')?->configArray(), 'rows.2.tiles.*.key'))->toBe(['A1', 'A2', 'A3'])
        ->and(data_get($statusWidgets->firstWhere('title', 'Video Room 2 Energy Meter Status')?->layoutArray(), 'y'))->toBe(0)
        ->and(data_get($trendWidgets->firstWhere('title', 'Video Room 2 Energy Meter')?->configArray(), 'series.0.key'))->toBe('V1')
        ->and(data_get($trendWidgets->firstWhere('title', 'Video Room 2 Energy Meter')?->configArray(), 'series.2.key'))->toBe('V3')
        ->and(data_get($trendWidgets->firstWhere('title', 'Video Room 2 Energy Meter')?->layoutArray(), 'y'))->toBe(4)
        ->and(data_get($historyWidgets->firstWhere('title', 'BTS Energy meter Consumption')?->configArray(), 'series.0.key'))->toBe('total_energy_kwh')
        ->and(data_get($historyWidgets->firstWhere('title', 'BTS Energy meter Consumption')?->configArray(), 'bar_interval'))->toBe('daily')
        ->and(data_get($historyWidgets->firstWhere('title', 'BTS Energy meter Consumption')?->layoutArray(), 'w'))->toBe(8);
});
