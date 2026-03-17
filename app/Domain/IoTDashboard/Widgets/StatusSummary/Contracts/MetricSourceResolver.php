<?php

declare(strict_types=1);

namespace App\Domain\IoTDashboard\Widgets\StatusSummary\Contracts;

use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use App\Domain\IoTDashboard\Widgets\StatusSummary\StatusSummaryMetricSourceType;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use Carbon\CarbonImmutable;

interface MetricSourceResolver
{
    public function type(): StatusSummaryMetricSourceType;

    /**
     * @param  array<string, mixed>  $tile
     * @return array{value: int|float|null, timestamp: CarbonImmutable|null}
     */
    public function resolve(
        IoTDashboardWidget $widget,
        array $tile,
        ?DeviceTelemetryLog $latestLog,
    ): array;
}
