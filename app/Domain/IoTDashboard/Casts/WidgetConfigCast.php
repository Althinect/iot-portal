<?php

declare(strict_types=1);

namespace App\Domain\IoTDashboard\Casts;

use App\Domain\IoTDashboard\Contracts\WidgetConfig;
use App\Domain\IoTDashboard\Enums\WidgetType;
use App\Domain\IoTDashboard\Widgets\BarChart\BarChartConfig;
use App\Domain\IoTDashboard\Widgets\GaugeChart\GaugeChartConfig;
use App\Domain\IoTDashboard\Widgets\LineChart\LineChartConfig;
use App\Domain\IoTDashboard\Widgets\StateCard\StateCardConfig;
use App\Domain\IoTDashboard\Widgets\StateTimeline\StateTimelineConfig;
use App\Domain\IoTDashboard\Widgets\StatusSummary\StatusSummaryConfig;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * @implements CastsAttributes<WidgetConfig, mixed>
 */
class WidgetConfigCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): WidgetConfig
    {
        $resolvedType = $this->resolveWidgetType($attributes);
        $rawConfig = is_array($value)
            ? $value
            : json_decode(
                is_string($value) ? $value : '{}',
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

        return $this->makeConfig($resolvedType, is_array($rawConfig) ? $rawConfig : []);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        $resolvedType = $this->resolveWidgetType($attributes);

        if ($value instanceof WidgetConfig) {
            return [
                $key => json_encode($value->toArray(), JSON_THROW_ON_ERROR),
            ];
        }

        $rawConfig = is_array($value) ? $value : [];

        return [
            $key => json_encode(
                $this->makeConfig($resolvedType, $rawConfig)->toArray(),
                JSON_THROW_ON_ERROR,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function makeConfig(WidgetType $widgetType, array $config): WidgetConfig
    {
        return match ($widgetType) {
            WidgetType::LineChart => LineChartConfig::fromArray($config),
            WidgetType::BarChart => BarChartConfig::fromArray($config),
            WidgetType::GaugeChart => GaugeChartConfig::fromArray($config),
            WidgetType::StatusSummary => StatusSummaryConfig::fromArray($config),
            WidgetType::StateCard => StateCardConfig::fromArray($config),
            WidgetType::StateTimeline => StateTimelineConfig::fromArray($config),
        };
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function resolveWidgetType(array $attributes): WidgetType
    {
        $rawType = $attributes['type'] ?? null;
        $type = is_string($rawType) ? $rawType : '';

        return WidgetType::tryFrom($type) ?? WidgetType::LineChart;
    }
}
