<?php

declare(strict_types=1);

use App\Domain\IoTDashboard\Application\DashboardHistoryRange;
use App\Domain\IoTDashboard\Application\DashboardSnapshotCache;
use App\Domain\IoTDashboard\Models\IoTDashboard;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-17 12:00:00', 'UTC'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-17 12:00:00', 'UTC'));

    config([
        'cache.default' => 'array',
        'iot_dashboard.snapshot_cache_seconds' => 10,
    ]);

    Cache::flush();
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

it('reuses cached dashboard widget snapshots for the same live request', function (): void {
    $dashboard = IoTDashboard::factory()->create();
    $widget = IoTDashboardWidget::factory()->create([
        'iot_dashboard_id' => $dashboard->id,
    ]);
    $cache = app(DashboardSnapshotCache::class);
    $calls = 0;

    $first = $cache->remember($dashboard, collect([$widget]), null, function () use (&$calls): array {
        $calls++;

        return [['sequence' => $calls]];
    });
    $second = $cache->remember($dashboard, collect([$widget]), null, function () use (&$calls): array {
        $calls++;

        return [['sequence' => $calls]];
    });

    expect($calls)->toBe(1)
        ->and($first)->toBe([['sequence' => 1]])
        ->and($second)->toBe($first);
});

it('separates cache entries by history range and widget update fingerprint', function (): void {
    $dashboard = IoTDashboard::factory()->create();
    $widget = IoTDashboardWidget::factory()->create([
        'iot_dashboard_id' => $dashboard->id,
    ]);
    $cache = app(DashboardSnapshotCache::class);
    $calls = 0;
    $range = new DashboardHistoryRange(
        fromAt: CarbonImmutable::now()->subHour(),
        untilAt: CarbonImmutable::now(),
    );
    $otherRange = new DashboardHistoryRange(
        fromAt: CarbonImmutable::now()->subHours(2),
        untilAt: CarbonImmutable::now(),
    );

    $cache->remember($dashboard, collect([$widget]), $range, function () use (&$calls): array {
        $calls++;

        return [['sequence' => $calls]];
    });
    $cache->remember($dashboard, collect([$widget]), $otherRange, function () use (&$calls): array {
        $calls++;

        return [['sequence' => $calls]];
    });

    Carbon::setTestNow(Carbon::parse('2026-06-17 12:00:03', 'UTC'));
    $widget->update(['title' => 'Updated widget']);

    $cache->remember($dashboard, collect([$widget->fresh()]), $range, function () use (&$calls): array {
        $calls++;

        return [['sequence' => $calls]];
    });

    expect($calls)->toBe(3);
});

it('bypasses snapshot caching when the ttl is disabled', function (): void {
    config()->set('iot_dashboard.snapshot_cache_seconds', 0);

    $dashboard = IoTDashboard::factory()->create();
    $widget = IoTDashboardWidget::factory()->create([
        'iot_dashboard_id' => $dashboard->id,
    ]);
    $cache = app(DashboardSnapshotCache::class);
    $calls = 0;

    $cache->remember($dashboard, collect([$widget]), null, function () use (&$calls): array {
        $calls++;

        return [['sequence' => $calls]];
    });
    $cache->remember($dashboard, collect([$widget]), null, function () use (&$calls): array {
        $calls++;

        return [['sequence' => $calls]];
    });

    expect($calls)->toBe(2);
});

it('bypasses snapshot caching for threshold widgets because policy state is external to the widget', function (): void {
    $dashboard = IoTDashboard::factory()->create();
    $widget = IoTDashboardWidget::factory()->create([
        'iot_dashboard_id' => $dashboard->id,
        'type' => 'threshold_status_card',
    ]);
    $cache = app(DashboardSnapshotCache::class);
    $calls = 0;

    $cache->remember($dashboard, collect([$widget]), null, function () use (&$calls): array {
        $calls++;

        return [['sequence' => $calls]];
    });
    $cache->remember($dashboard, collect([$widget]), null, function () use (&$calls): array {
        $calls++;

        return [['sequence' => $calls]];
    });

    expect($calls)->toBe(2);
});
