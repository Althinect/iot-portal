<?php

declare(strict_types=1);

namespace App\Http\Controllers\IoTDashboard;

use App\Domain\IoTDashboard\Application\DashboardSnapshotCache;
use App\Domain\IoTDashboard\Application\WidgetRegistry;
use App\Domain\IoTDashboard\Models\IoTDashboard;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\ShowIoTDashboardSnapshotsRequest;
use Illuminate\Http\JsonResponse;

class PortalIoTDashboardSnapshotsController extends Controller
{
    public function __invoke(
        ShowIoTDashboardSnapshotsRequest $request,
        Organization $organization,
        IoTDashboard $dashboard,
        IoTDashboardSnapshotsController $snapshotsController,
        WidgetRegistry $widgetRegistry,
        DashboardSnapshotCache $snapshotCache,
    ): JsonResponse {
        $user = $request->user();

        abort_unless($user instanceof User && $user->canAccessTenant($organization), 403);
        abort_unless((int) $dashboard->organization_id === (int) $organization->id, 404);

        return $snapshotsController(
            $request,
            $dashboard,
            $widgetRegistry,
            $snapshotCache,
        );
    }
}
