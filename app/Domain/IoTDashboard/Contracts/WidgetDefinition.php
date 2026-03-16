<?php

declare(strict_types=1);

namespace App\Domain\IoTDashboard\Contracts;

use App\Domain\IoTDashboard\Application\DashboardHistoryRange;
use App\Domain\IoTDashboard\Enums\WidgetType;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;

interface WidgetDefinition
{
    public function type(): WidgetType;

    /**
     * @param  array<string, mixed>  $config
     */
    public function makeConfig(array $config): WidgetConfig;

    /**
     * @return array<string, mixed>
     */
    public function resolveSnapshot(IoTDashboardWidget $widget, ?DashboardHistoryRange $historyRange = null): array;

    /**
     * @return array<string, mixed>
     */
    public function bootstrapPayload(IoTDashboardWidget $widget): array;
}
