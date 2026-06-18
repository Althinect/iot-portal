<?php

declare(strict_types=1);

namespace App\Domain\IoTDashboard\Widgets\LineChart;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Models\VirtualDeviceLink;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Domain\IoTDashboard\Application\DashboardHistoryRange;
use App\Domain\IoTDashboard\Contracts\WidgetConfig;
use App\Domain\IoTDashboard\Contracts\WidgetSnapshotResolver;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use App\Domain\Telemetry\Services\TelemetryQueryService;
use Carbon\CarbonInterface;
use InvalidArgumentException;

class LineChartSnapshotResolver implements WidgetSnapshotResolver
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
        if (! $config instanceof LineChartConfig) {
            throw new InvalidArgumentException('Line chart widgets require LineChartConfig.');
        }

        $series = [];
        $pointsBySourceAndParameter = [];
        $range = $this->resolveHistoryWindow($config, $historyRange);

        foreach ($config->series() as $seriesConfiguration) {
            $key = $seriesConfiguration['key'];
            $source = array_key_exists('source', $seriesConfiguration) ? $seriesConfiguration['source'] : [];
            $sourceBinding = $this->resolveSeriesSourceBinding($widget, $source);
            $sourceKey = $this->sourceCacheKey($sourceBinding).':'.$key;
            $points = $pointsBySourceAndParameter[$sourceKey] ??= $sourceBinding === null
                ? []
                : $this->telemetryQuery->numericSeries(
                    deviceId: $sourceBinding['device_id'],
                    schemaVersionTopicId: $sourceBinding['schema_version_topic_id'],
                    parameterKey: $key,
                    fromAt: $range['from_at'],
                    untilAt: $range['until_at'],
                    maxPoints: $config->maxPoints(),
                );

            $resolvedSeries = [
                'key' => $seriesConfiguration['key'],
                'label' => $seriesConfiguration['label'],
                'color' => $seriesConfiguration['color'],
                'points' => $points,
            ];

            if (array_key_exists('source', $seriesConfiguration)) {
                $resolvedSeries['source'] = $seriesConfiguration['source'];
            }

            $series[] = $resolvedSeries;
        }

        return [
            'widget_id' => (int) $widget->id,
            'generated_at' => now()->toIso8601String(),
            'series' => $series,
        ];
    }

    /**
     * @param  array<string, mixed>|mixed  $source
     * @return array{device_id: int, schema_version_topic_id: int}|null
     */
    private function resolveSeriesSourceBinding(IoTDashboardWidget $widget, mixed $source): ?array
    {
        if (is_array($source) && ($source['type'] ?? null) === 'virtual_device_link') {
            $purpose = is_string($source['purpose'] ?? null) ? trim((string) $source['purpose']) : '';

            if ($purpose === '') {
                return null;
            }

            $sourceDevice = $this->resolveVirtualSourceDevice($widget, $purpose);

            return $sourceDevice instanceof Device
                ? $this->bindingForDevice($sourceDevice)
                : null;
        }

        $deviceId = (int) $widget->device_id;
        $schemaVersionTopicId = (int) $widget->schema_version_topic_id;

        if ($deviceId < 1 || $schemaVersionTopicId < 1) {
            return null;
        }

        return [
            'device_id' => $deviceId,
            'schema_version_topic_id' => $schemaVersionTopicId,
        ];
    }

    private function resolveVirtualSourceDevice(IoTDashboardWidget $widget, string $purpose): ?Device
    {
        $virtualDeviceId = (int) $widget->device_id;

        if ($virtualDeviceId < 1) {
            return null;
        }

        $link = VirtualDeviceLink::query()
            ->with('sourceDevice.schemaVersion.topics')
            ->where('virtual_device_id', $virtualDeviceId)
            ->where('purpose', $purpose)
            ->orderBy('sequence')
            ->first();

        return $link?->sourceDevice;
    }

    /**
     * @return array{device_id: int, schema_version_topic_id: int}|null
     */
    private function bindingForDevice(Device $device): ?array
    {
        $device->loadMissing('schemaVersion.topics');

        $topic = $device->schemaVersion?->topics
            ?->first(fn (SchemaVersionTopic $topic): bool => $topic->key === 'telemetry')
            ?? $device->schemaVersion?->topics?->first(fn (SchemaVersionTopic $topic): bool => $topic->isPublish());

        if (! $topic instanceof SchemaVersionTopic) {
            return null;
        }

        return [
            'device_id' => (int) $device->id,
            'schema_version_topic_id' => (int) $topic->id,
        ];
    }

    /**
     * @param  array{device_id: int, schema_version_topic_id: int}|null  $sourceBinding
     */
    private function sourceCacheKey(?array $sourceBinding): string
    {
        if ($sourceBinding === null) {
            return 'missing';
        }

        return $sourceBinding['device_id'].':'.$sourceBinding['schema_version_topic_id'];
    }

    /**
     * @return array{from_at: CarbonInterface, until_at: CarbonInterface}
     */
    private function resolveHistoryWindow(LineChartConfig $config, ?DashboardHistoryRange $historyRange): array
    {
        if ($historyRange instanceof DashboardHistoryRange) {
            return [
                'from_at' => $historyRange->fromAt(),
                'until_at' => $historyRange->untilAt(),
            ];
        }

        return [
            'from_at' => now()->subMinutes($config->lookbackMinutes()),
            'until_at' => now(),
        ];
    }
}
