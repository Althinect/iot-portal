<?php

declare(strict_types=1);

namespace App\Http\Controllers\IoTDashboard;

use App\Domain\IoTDashboard\Models\IoTDashboard;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateIoTDashboardWidgetLayoutRequest;
use Illuminate\Http\JsonResponse;

class IoTDashboardWidgetLayoutController extends Controller
{
    /**
     * @return JsonResponse
     */
    public function __invoke(
        UpdateIoTDashboardWidgetLayoutRequest $request,
        IoTDashboard $dashboard,
        IoTDashboardWidget $widget,
    ) {
        abort_unless((int) $widget->iot_dashboard_id === (int) $dashboard->id, 404);

        $layout = [
            'x' => $request->integer('x'),
            'y' => $request->integer('y'),
            'w' => $request->integer('w'),
            'h' => $request->integer('h'),
        ];

        $widget->forceFill([
            'layout' => $layout,
        ])->save();

        return response()->json([
            'widget_id' => (int) $widget->id,
            'layout' => $widget->layoutArray(),
        ]);
    }
}
