<?php

declare(strict_types=1);

namespace App\Domain\IoTDashboard\Widgets\StatusSummary;

use App\Domain\IoTDashboard\Application\LatestTelemetryState;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use App\Domain\IoTDashboard\Widgets\StatusSummary\Contracts\MetricSourceResolver;

final class LatestParameterMetricSourceResolver implements MetricSourceResolver
{
    public function type(): StatusSummaryMetricSourceType
    {
        return StatusSummaryMetricSourceType::LatestParameter;
    }

    public function resolve(
        IoTDashboardWidget $widget,
        array $tile,
        ?LatestTelemetryState $latestState,
    ): array {
        $parameterKey = is_string(data_get($tile, 'source.parameter_key'))
            ? trim((string) data_get($tile, 'source.parameter_key'))
            : '';

        if ($parameterKey === '' || ! $latestState instanceof LatestTelemetryState) {
            return ['value' => null, 'timestamp' => null];
        }

        return [
            'value' => $latestState->numericValue($parameterKey),
            'timestamp' => $latestState->recordedAt,
        ];
    }
}
