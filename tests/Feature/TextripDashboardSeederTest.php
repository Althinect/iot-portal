<?php

declare(strict_types=1);

use App\Domain\IoTDashboard\Models\IoTDashboard;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use Database\Seeders\TextripDashboardSeeder;
use Database\Seeders\TextripMigrationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds textrip equivalent core energy and tank dashboards for the recovered devices', function (): void {
    $this->seed(TextripMigrationSeeder::class);
    $this->seed(TextripDashboardSeeder::class);

    $dashboards = IoTDashboard::query()
        ->whereIn('slug', [
            'textrip-energy-overview',
            'textrip-energy-history',
            'textrip-tanks-overview',
            'textrip-tanks-history',
        ])
        ->get()
        ->keyBy('slug');

    expect($dashboards)->toHaveCount(4);

    $energyOverview = $dashboards->get('textrip-energy-overview');
    $energyHistory = $dashboards->get('textrip-energy-history');
    $tanksOverview = $dashboards->get('textrip-tanks-overview');
    $tanksHistory = $dashboards->get('textrip-tanks-history');

    expect($energyOverview)->not->toBeNull()
        ->and($energyHistory)->not->toBeNull()
        ->and($tanksOverview)->not->toBeNull()
        ->and($tanksHistory)->not->toBeNull();

    $energyOverviewWidgets = IoTDashboardWidget::query()
        ->where('iot_dashboard_id', $energyOverview?->id)
        ->orderBy('sequence')
        ->get();
    $energyHistoryWidgets = IoTDashboardWidget::query()
        ->where('iot_dashboard_id', $energyHistory?->id)
        ->orderBy('sequence')
        ->get();
    $tankOverviewWidgets = IoTDashboardWidget::query()
        ->where('iot_dashboard_id', $tanksOverview?->id)
        ->orderBy('sequence')
        ->get();
    $tankHistoryWidgets = IoTDashboardWidget::query()
        ->where('iot_dashboard_id', $tanksHistory?->id)
        ->orderBy('sequence')
        ->get();

    expect($energyOverviewWidgets)->toHaveCount(11)
        ->and($energyHistoryWidgets)->toHaveCount(11)
        ->and($tankOverviewWidgets)->toHaveCount(7)
        ->and($tankHistoryWidgets)->toHaveCount(7)
        ->and($energyOverviewWidgets->every(fn (IoTDashboardWidget $widget): bool => $widget->type === 'status_summary'))->toBeTrue()
        ->and($energyHistoryWidgets->every(fn (IoTDashboardWidget $widget): bool => $widget->type === 'bar_chart'))->toBeTrue()
        ->and($tankOverviewWidgets->every(fn (IoTDashboardWidget $widget): bool => $widget->type === 'status_summary'))->toBeTrue()
        ->and($tankHistoryWidgets->every(fn (IoTDashboardWidget $widget): bool => $widget->type === 'line_chart'))->toBeTrue()
        ->and($energyOverviewWidgets->pluck('device.external_id')->all())->toContain(
            '869244041759394-21',
            '869244041759394-27',
            '869604063874100-22',
        )
        ->and($tankOverviewWidgets->pluck('device.external_id')->all())->toContain(
            '869604063839871-51',
            '869604063866064-51',
            '869604063872807-52',
        );

    $textripMainStatus = $energyOverviewWidgets->firstWhere('title', 'Textrip Main');
    $textripMainHistory = $energyHistoryWidgets->firstWhere('title', 'Textrip Main History');
    $dieselTankStatus = $tankOverviewWidgets->firstWhere('title', '3000L Diesel tank');
    $dieselTankHistory = $tankHistoryWidgets->firstWhere('title', '10000L Diesel tank History');

    expect($textripMainStatus)->not->toBeNull()
        ->and(data_get($textripMainStatus?->configArray(), 'rows.0.tiles.0.key'))->toBe('TotalEnergy')
        ->and(data_get($textripMainStatus?->configArray(), 'rows.1.tiles.*.key'))->toBe([
            'PhaseAVoltage',
            'PhaseBVoltage',
            'PhaseCVoltage',
        ])
        ->and(data_get($textripMainStatus?->configArray(), 'rows.2.tiles.*.key'))->toBe([
            'TotalActivePower',
            'TotalReactivePower',
            'totalPowerFactor',
        ])
        ->and(data_get($textripMainStatus?->resolvedSeriesConfig(), '0.key'))->toBe('TotalEnergy')
        ->and(data_get($textripMainStatus?->resolvedSeriesConfig(), '6.key'))->toBe('totalPowerFactor')
        ->and(data_get($textripMainStatus?->layoutArray(), 'y'))->toBe(8)
        ->and($textripMainHistory)->not->toBeNull()
        ->and(data_get($textripMainHistory?->configArray(), 'series.0.key'))->toBe('TotalEnergy')
        ->and(data_get($textripMainHistory?->configArray(), 'bar_interval'))->toBe('daily')
        ->and(data_get($textripMainHistory?->layoutArray(), 'y'))->toBe(10)
        ->and($dieselTankStatus)->not->toBeNull()
        ->and(data_get($dieselTankStatus?->configArray(), 'rows.0.tiles.0.key'))->toBe('ioid1')
        ->and(data_get($dieselTankStatus?->configArray(), 'rows.0.tiles.0.unit'))->toBe('Litres')
        ->and(data_get($dieselTankStatus?->layoutArray(), 'x'))->toBe(16)
        ->and($dieselTankHistory)->not->toBeNull()
        ->and(data_get($dieselTankHistory?->configArray(), 'series.0.key'))->toBe('ioid1')
        ->and(data_get($dieselTankHistory?->layoutArray(), 'x'))->toBe(12)
        ->and(data_get($dieselTankHistory?->layoutArray(), 'y'))->toBe(4);
});
