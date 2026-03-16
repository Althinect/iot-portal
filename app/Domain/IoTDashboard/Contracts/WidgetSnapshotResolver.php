<?php

declare(strict_types=1);

namespace App\Domain\IoTDashboard\Contracts;

use App\Domain\IoTDashboard\Application\DashboardHistoryRange;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;

interface WidgetSnapshotResolver
{
    /**
     * @return array<string, mixed>
     */
    public function resolve(
        IoTDashboardWidget $widget,
        WidgetConfig $config,
        ?DashboardHistoryRange $historyRange = null,
    ): array;
}
