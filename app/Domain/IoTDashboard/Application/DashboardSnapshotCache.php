<?php

declare(strict_types=1);

namespace App\Domain\IoTDashboard\Application;

use App\Domain\IoTDashboard\Models\IoTDashboard;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class DashboardSnapshotCache
{
    private const array UNCACHEABLE_WIDGET_TYPES = [
        'threshold_status_card',
        'threshold_status_grid',
    ];

    /**
     * @param  Collection<int, mixed>  $widgets
     * @param  Closure(): array<int, array<string, mixed>>  $callback
     * @return array<int, array<string, mixed>>
     */
    public function remember(IoTDashboard $dashboard, Collection $widgets, ?DashboardHistoryRange $historyRange, Closure $callback): array
    {
        $ttlSeconds = $this->ttlSeconds();

        if ($ttlSeconds < 1 || ! $this->widgetsAreCacheable($widgets)) {
            return $callback();
        }

        return Cache::remember(
            $this->cacheKey($dashboard, $widgets, $historyRange),
            $ttlSeconds,
            $callback,
        );
    }

    private function ttlSeconds(): int
    {
        $ttl = config('iot_dashboard.snapshot_cache_seconds', 2);

        return is_numeric($ttl) ? max(0, (int) $ttl) : 2;
    }

    /**
     * @param  Collection<int, mixed>  $widgets
     */
    private function widgetsAreCacheable(Collection $widgets): bool
    {
        return $widgets->every(function (mixed $widget): bool {
            if (! is_object($widget) || ! method_exists($widget, 'getAttribute')) {
                return true;
            }

            return ! in_array((string) $widget->getAttribute('type'), self::UNCACHEABLE_WIDGET_TYPES, true);
        });
    }

    /**
     * @param  Collection<int, mixed>  $widgets
     */
    private function cacheKey(IoTDashboard $dashboard, Collection $widgets, ?DashboardHistoryRange $historyRange): string
    {
        $widgetFingerprint = $widgets
            ->map(function (mixed $widget): string {
                $id = is_object($widget) && isset($widget->id) ? (string) $widget->id : '0';
                $updatedAt = is_object($widget) && method_exists($widget, 'getAttribute')
                    ? (string) ($widget->getAttribute('updated_at')?->getTimestamp() ?? 0)
                    : '0';

                return "{$id}:{$updatedAt}";
            })
            ->sort()
            ->implode('|');
        $rangeFingerprint = $historyRange instanceof DashboardHistoryRange
            ? $historyRange->fromAt()->toIso8601String().'..'.$historyRange->untilAt()->toIso8601String()
            : 'live';
        $dashboardUpdatedAt = $dashboard->updated_at?->getTimestamp() ?? 0;

        return 'iot-dashboard:snapshots:'.sha1(implode('|', [
            (string) $dashboard->id,
            (string) $dashboardUpdatedAt,
            $rangeFingerprint,
            $widgetFingerprint,
        ]));
    }
}
