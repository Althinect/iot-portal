<?php

declare(strict_types=1);

namespace App\Http\Controllers\IoTDashboard;

use App\Domain\IoTDashboard\Application\WidgetRegistry;
use App\Domain\IoTDashboard\Models\IoTDashboard;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use App\Http\Controllers\Controller;
use App\Http\Requests\ShowIoTDashboardSnapshotsRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class IoTDashboardSnapshotsController extends Controller
{
    public function __invoke(
        ShowIoTDashboardSnapshotsRequest $request,
        IoTDashboard $dashboard,
        WidgetRegistry $widgetRegistry,
    ): JsonResponse {
        Gate::authorize('view', $dashboard);

        $dashboard->loadMissing([
            'widgets' => fn ($query) => $query
                ->with([
                    'topic:id,label,suffix',
                    'device:id,uuid,name,organization_id,connection_state,last_seen_at,offline_deadline_at,presence_timeout_seconds',
                ])
                ->orderBy('sequence')
                ->orderBy('id'),
        ]);

        $widgetId = $request->widgetId();
        $widgetIds = $request->widgetIds();
        $historyRange = $request->historyRange();

        $widgets = $dashboard->widgets
            ->when(
                $widgetIds !== [],
                fn ($collection) => $collection->whereIn('id', $widgetIds),
            )
            ->when(
                $widgetIds === [] && $widgetId > 0,
                fn ($collection) => $collection->where('id', $widgetId),
            )
            ->values();

        $snapshots = $widgets
            ->map(function (IoTDashboardWidget $widget) use ($historyRange, $widgetRegistry): array {
                $definition = $widgetRegistry->forWidget($widget);
                $snapshot = $definition->resolveSnapshot($widget, $historyRange);

                return [
                    'id' => (int) $widget->id,
                    'type' => $widget->type,
                    ...$snapshot,
                ];
            })
            ->all();

        return response()->json([
            'version' => '2.0',
            'dashboard_id' => (int) $dashboard->id,
            'generated_at' => now()->toIso8601String(),
            'widgets' => $snapshots,
        ]);
    }
}
