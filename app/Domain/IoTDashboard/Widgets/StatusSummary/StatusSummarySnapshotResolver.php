<?php

declare(strict_types=1);

namespace App\Domain\IoTDashboard\Widgets\StatusSummary;

use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\ProfileDerivedParameterDefinition;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use App\Domain\IoTDashboard\Application\DashboardHistoryRange;
use App\Domain\IoTDashboard\Application\HotStateLatestTelemetryReader;
use App\Domain\IoTDashboard\Contracts\WidgetConfig;
use App\Domain\IoTDashboard\Contracts\WidgetSnapshotResolver;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use App\Domain\IoTDashboard\Widgets\Concerns\InterpretsThresholdStatusSnapshot;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class StatusSummarySnapshotResolver implements WidgetSnapshotResolver
{
    use InterpretsThresholdStatusSnapshot;

    public function __construct(
        private readonly LatestParameterMetricSourceResolver $latestParameterResolver,
        private readonly HotStateLatestTelemetryReader $latestTelemetryReader,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function resolve(
        IoTDashboardWidget $widget,
        WidgetConfig $config,
        ?DashboardHistoryRange $historyRange = null,
    ): array {
        if (! $config instanceof StatusSummaryConfig) {
            throw new InvalidArgumentException('Status summary widgets require StatusSummaryConfig.');
        }

        $series = [];
        $latestState = $widget->device === null || $widget->topic === null
            ? null
            : $this->latestTelemetryReader->read($widget->device, $widget->topic, $config->lookbackMinutes());

        foreach ($config->tiles() as $tile) {
            $resolvedTile = $this->latestParameterResolver->resolve($widget, $tile, $latestState);
            $resolvedColor = $this->resolveTileColor($tile, $resolvedTile['value']);
            $resolvedUnit = $this->resolveTileUnit($widget, $tile);

            $series[] = [
                'key' => $tile['key'],
                'label' => $tile['label'],
                'unit' => $resolvedUnit,
                'color' => $resolvedColor,
                'points' => $resolvedTile['value'] === null || ! $resolvedTile['timestamp'] instanceof CarbonImmutable
                    ? []
                    : [[
                        'timestamp' => $resolvedTile['timestamp']->toIso8601String(),
                        'value' => $resolvedTile['value'],
                    ]],
            ];
        }

        $device = $widget->device;

        return [
            'widget_id' => (int) $widget->id,
            'generated_at' => now()->toIso8601String(),
            'device_connection_state' => $device?->effectiveConnectionState(),
            'device_last_seen_at' => $device?->lastSeenAt()?->toIso8601String(),
            'series' => $series,
        ];
    }

    /**
     * @param  array{
     *     threshold_ranges: array<int, array{from: int|float|null, to: int|float|null, color: string}>,
     *     base_color: string
     * }  $tile
     */
    private function resolveTileColor(array $tile, int|float|null $value): string
    {
        if ($value === null) {
            return $tile['base_color'];
        }

        foreach ($tile['threshold_ranges'] as $range) {
            $from = $range['from'];
            $to = $range['to'];

            if ($from !== null && $value < $from) {
                continue;
            }

            if ($to !== null && $value > $to) {
                continue;
            }

            return $range['color'];
        }

        return $tile['base_color'];
    }

    /**
     * @param  array{unit: string|null, source: array<string, mixed>}  $tile
     */
    private function resolveTileUnit(IoTDashboardWidget $widget, array $tile): ?string
    {
        if (is_string($tile['unit'] ?? null) && trim($tile['unit']) !== '') {
            return $this->resolveUnitSymbol(trim($tile['unit']));
        }

        $parameterKey = is_string($tile['source']['parameter_key'] ?? null)
            ? trim($tile['source']['parameter_key'])
            : '';

        if ($parameterKey === '') {
            return null;
        }

        $parameterUnit = ProfileParameterDefinition::query()
            ->where('device_channel_id', (int) $widget->device_channel_id)
            ->where('key', $parameterKey)
            ->value('unit');

        if (is_string($parameterUnit) && trim($parameterUnit) !== '') {
            return $this->resolveUnitSymbol(trim($parameterUnit));
        }

        $topic = DeviceChannel::query()
            ->whereKey((int) $widget->device_channel_id)
            ->first(['id', 'device_profile_version_id']);

        if (! $topic instanceof DeviceChannel) {
            return null;
        }

        $derivedUnit = ProfileDerivedParameterDefinition::query()
            ->where('device_profile_version_id', $topic->device_profile_version_id)
            ->where('key', $parameterKey)
            ->value('unit');

        return is_string($derivedUnit) && trim($derivedUnit) !== ''
            ? $this->resolveUnitSymbol(trim($derivedUnit))
            : null;
    }
}
