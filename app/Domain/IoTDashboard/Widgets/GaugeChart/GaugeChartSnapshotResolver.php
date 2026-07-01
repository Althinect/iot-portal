<?php

declare(strict_types=1);

namespace App\Domain\IoTDashboard\Widgets\GaugeChart;

use App\Domain\IoTDashboard\Application\DashboardHistoryRange;
use App\Domain\IoTDashboard\Contracts\WidgetConfig;
use App\Domain\IoTDashboard\Contracts\WidgetSnapshotResolver;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use App\Domain\Telemetry\Services\TelemetryQueryService;
use InvalidArgumentException;

class GaugeChartSnapshotResolver implements WidgetSnapshotResolver
{
    public function __construct(
        private readonly TelemetryQueryService $telemetryQuery,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function resolve(
        IoTDashboardWidget $widget,
        WidgetConfig $config,
        ?DashboardHistoryRange $historyRange = null,
    ): array {
        if (! $config instanceof GaugeChartConfig) {
            throw new InvalidArgumentException('Gauge chart widgets require GaugeChartConfig.');
        }

        $deviceId = is_numeric($widget->device_id) ? (int) $widget->device_id : null;
        $fromAt = now()->subMinutes($config->lookbackMinutes());
        $untilAt = now();

        $series = [];

        foreach ($config->series() as $seriesConfiguration) {
            $series[] = [
                'key' => $seriesConfiguration['key'],
                'label' => $seriesConfiguration['label'],
                'color' => $seriesConfiguration['color'],
                'points' => $deviceId === null
                    ? []
                    : $this->telemetryQuery->numericSeries(
                        deviceId: $deviceId,
                        deviceChannelId: (int) $widget->device_channel_id,
                        parameterKey: $seriesConfiguration['key'],
                        fromAt: $fromAt,
                        untilAt: $untilAt,
                        maxPoints: $config->maxPoints(),
                    ),
            ];
        }

        return [
            'widget_id' => (int) $widget->id,
            'generated_at' => now()->toIso8601String(),
            'series' => $series,
        ];
    }
}
