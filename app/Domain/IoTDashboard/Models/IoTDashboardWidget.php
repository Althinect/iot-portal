<?php

declare(strict_types=1);

namespace App\Domain\IoTDashboard\Models;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Domain\IoTDashboard\Casts\WidgetConfigCast;
use App\Domain\IoTDashboard\Casts\WidgetLayoutCast;
use App\Domain\IoTDashboard\Contracts\WidgetConfig;
use App\Domain\IoTDashboard\Enums\WidgetType;
use App\Domain\IoTDashboard\Widgets\BarChart\BarChartConfig;
use App\Domain\IoTDashboard\Widgets\GaugeChart\GaugeChartConfig;
use App\Domain\IoTDashboard\Widgets\LineChart\LineChartConfig;
use App\Domain\IoTDashboard\Widgets\StateCard\StateCardConfig;
use App\Domain\IoTDashboard\Widgets\StateTimeline\StateTimelineConfig;
use App\Domain\IoTDashboard\Widgets\StatusSummary\StatusSummaryConfig;
use Database\Factories\Domain\IoTDashboard\Models\IoTDashboardWidgetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IoTDashboardWidget extends Model
{
    /** @use HasFactory<IoTDashboardWidgetFactory> */
    use HasFactory;

    protected $table = 'iot_dashboard_widgets';

    protected $guarded = ['id'];

    protected static function newFactory(): IoTDashboardWidgetFactory
    {
        return IoTDashboardWidgetFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'config' => WidgetConfigCast::class,
            'layout' => WidgetLayoutCast::class,
            'sequence' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<IoTDashboard, $this>
     */
    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(IoTDashboard::class, 'iot_dashboard_id');
    }

    /**
     * @return BelongsTo<SchemaVersionTopic, $this>
     */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(SchemaVersionTopic::class, 'schema_version_topic_id');
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function widgetType(): WidgetType
    {
        return WidgetType::tryFrom((string) $this->type) ?? WidgetType::LineChart;
    }

    public function configObject(): WidgetConfig
    {
        $config = $this->getAttribute('config');

        if ($config instanceof WidgetConfig) {
            return $config;
        }

        return match ($this->widgetType()) {
            WidgetType::LineChart => LineChartConfig::fromArray([]),
            WidgetType::BarChart => BarChartConfig::fromArray([]),
            WidgetType::GaugeChart => GaugeChartConfig::fromArray([]),
            WidgetType::StatusSummary => StatusSummaryConfig::fromArray([]),
            WidgetType::StateCard => StateCardConfig::fromArray([]),
            WidgetType::StateTimeline => StateTimelineConfig::fromArray([]),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function configArray(): array
    {
        $config = $this->getAttribute('config');

        if ($config instanceof WidgetConfig) {
            return $config->toArray();
        }

        return is_array($config) ? $config : [];
    }

    /**
     * @return array{x: int, y: int, w: int, h: int, columns: int, card_height_px: int}
     */
    public function layoutArray(): array
    {
        $layout = $this->getAttribute('layout');

        if (! is_array($layout)) {
            return [
                'x' => 0,
                'y' => 0,
                'w' => 6,
                'h' => 4,
                'columns' => 24,
                'card_height_px' => 384,
            ];
        }

        return [
            'x' => $this->toInt($layout['x'] ?? null, 0),
            'y' => $this->toInt($layout['y'] ?? null, 0),
            'w' => $this->toInt($layout['w'] ?? null, 6),
            'h' => $this->toInt($layout['h'] ?? null, 4),
            'columns' => $this->toInt($layout['columns'] ?? null, 24),
            'card_height_px' => $this->toInt($layout['card_height_px'] ?? null, 384),
        ];
    }

    /**
     * @return array<int, array{key: string, label: string, color: string, unit?: string|null}>
     */
    public function resolvedSeriesConfig(): array
    {
        $config = $this->getAttribute('config');

        if ($config instanceof WidgetConfig) {
            return $config->series();
        }

        return [];
    }

    private function toInt(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) round((float) $value) : $default;
    }
}
