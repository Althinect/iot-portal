<?php

declare(strict_types=1);

namespace App\Http\Controllers\IoTDashboard;

use App\Domain\IoTDashboard\Models\IoTDashboard;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use App\Domain\Shared\Models\Organization;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateIoTDashboardWidgetLayoutRequest;
use Illuminate\Http\JsonResponse;

class PortalIoTDashboardWidgetLayoutController extends Controller
{
    public function __invoke(
        UpdateIoTDashboardWidgetLayoutRequest $request,
        Organization $organization,
        IoTDashboard $dashboard,
        IoTDashboardWidget $widget,
    ): JsonResponse {
        abort_unless((int) $dashboard->organization_id === (int) $organization->id, 404);
        abort_unless((int) $widget->iot_dashboard_id === (int) $dashboard->id, 404);

        $widget->forceFill([
            'layout' => [
                'x' => $request->integer('x'),
                'y' => $request->integer('y'),
                'w' => $request->integer('w'),
                'h' => $request->integer('h'),
            ],
        ])->save();

        return response()->json([
            'widget_id' => (int) $widget->id,
            'layout' => $widget->layoutArray(),
        ]);
    }
}
