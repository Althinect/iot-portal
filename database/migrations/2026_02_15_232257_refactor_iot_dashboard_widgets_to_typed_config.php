<?php

declare(strict_types=1);

use App\Domain\IoTDashboard\Enums\WidgetType;
use App\Domain\IoTDashboard\Widgets\BarChart\BarInterval;
use App\Domain\IoTDashboard\Widgets\GaugeChart\GaugeStyle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const int GRID_COLUMNS = 24;

    private const int GRID_CELL_HEIGHT = 96;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('iot_dashboard_widgets', function (Blueprint $table): void {
            $table->jsonb('config')->default('{}')->after('title');
            $table->jsonb('layout')->default('{}')->after('config');
        });

        DB::table('iot_dashboard_widgets')
            ->orderBy('id')
            ->chunkById(100, function ($widgets): void {
                foreach ($widgets as $widget) {
                    $this->backfillTypedConfig($widget);
                }
            });

        Schema::table('iot_dashboard_widgets', function (Blueprint $table): void {
            $table->dropColumn([
                'series_config',
                'options',
                'use_websocket',
                'use_polling',
                'polling_interval_seconds',
                'lookback_minutes',
                'max_points',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('iot_dashboard_widgets', function (Blueprint $table): void {
            $table->jsonb('series_config')->default('[]')->after('title');
            $table->jsonb('options')->nullable()->after('series_config');
            $table->boolean('use_websocket')->default(true)->after('options');
            $table->boolean('use_polling')->default(true)->after('use_websocket');
            $table->unsignedInteger('polling_interval_seconds')->default(10)->after('use_polling');
            $table->unsignedInteger('lookback_minutes')->default(120)->after('polling_interval_seconds');
            $table->unsignedInteger('max_points')->default(240)->after('lookback_minutes');
        });

        DB::table('iot_dashboard_widgets')
            ->orderBy('id')
            ->chunkById(100, function ($widgets): void {
                foreach ($widgets as $widget) {
                    $this->restoreLegacyShape($widget);
                }
            });

        Schema::table('iot_dashboard_widgets', function (Blueprint $table): void {
            $table->dropColumn(['config', 'layout']);
        });
    }

    private function backfillTypedConfig(object $widget): void
    {
        $type = WidgetType::tryFrom((string) $widget->type);

        if (! $type instanceof WidgetType) {
            throw new RuntimeException("Widget [{$widget->id}] has unsupported type [{$widget->type}].");
        }

        $series = $this->normalizeSeries($this->decodeJson($widget->series_config));

        if ($series === []) {
            throw new RuntimeException("Widget [{$widget->id}] has an empty series definition and cannot be migrated.");
        }

        $options = $this->decodeJson($widget->options);
        $config = $this->buildTypedConfig($type, $series, $options, $widget);
        $layout = $this->buildTypedLayout($options);

        DB::table('iot_dashboard_widgets')
            ->where('id', $widget->id)
            ->update([
                'config' => json_encode($config, JSON_THROW_ON_ERROR),
                'layout' => json_encode($layout, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
    }

    private function restoreLegacyShape(object $widget): void
    {
        $type = WidgetType::tryFrom((string) $widget->type) ?? WidgetType::LineChart;
        $config = $this->decodeJson($widget->config);
        $layout = $this->decodeJson($widget->layout);

        $transport = is_array($config['transport'] ?? null) ? $config['transport'] : [];
        $window = is_array($config['window'] ?? null) ? $config['window'] : [];

        $options = [
            'layout' => [
                'x' => $this->toInt($layout['x'] ?? 0, 0),
                'y' => $this->toInt($layout['y'] ?? 0, 0),
                'w' => $this->toInt($layout['w'] ?? 6, 6),
                'h' => $this->toInt($layout['h'] ?? 4, 4),
            ],
            'layout_columns' => self::GRID_COLUMNS,
            'grid_columns' => $this->toInt($layout['w'] ?? 6, 6),
            'card_height_px' => $this->toInt($layout['card_height_px'] ?? 384, 384),
        ];

        if ($type === WidgetType::BarChart) {
            $interval = is_string($config['bar_interval'] ?? null)
                ? strtolower(trim((string) $config['bar_interval']))
                : BarInterval::Hourly->value;
            $options['bar_interval'] = BarInterval::tryFrom($interval)?->value ?? BarInterval::Hourly->value;
        }

        if ($type === WidgetType::GaugeChart) {
            $style = is_string($config['gauge_style'] ?? null)
                ? strtolower(trim((string) $config['gauge_style']))
                : GaugeStyle::Classic->value;

            $options['gauge_style'] = GaugeStyle::tryFrom($style)?->value ?? GaugeStyle::Classic->value;
            $options['gauge_min'] = is_numeric($config['gauge_min'] ?? null) ? (float) $config['gauge_min'] : 0;
            $options['gauge_max'] = is_numeric($config['gauge_max'] ?? null) ? (float) $config['gauge_max'] : 100;
            $options['gauge_ranges'] = is_array($config['gauge_ranges'] ?? null)
                ? $config['gauge_ranges']
                : [
                    ['from' => 0, 'to' => 50, 'color' => '#10b981'],
                    ['from' => 50, 'to' => 80, 'color' => '#f59e0b'],
                    ['from' => 80, 'to' => 100, 'color' => '#ef4444'],
                ];
        }

        DB::table('iot_dashboard_widgets')
            ->where('id', $widget->id)
            ->update([
                'series_config' => json_encode($this->normalizeSeries($config['series'] ?? []), JSON_THROW_ON_ERROR),
                'options' => json_encode($options, JSON_THROW_ON_ERROR),
                'use_websocket' => (bool) ($transport['use_websocket'] ?? true),
                'use_polling' => (bool) ($transport['use_polling'] ?? true),
                'polling_interval_seconds' => $this->toInt($transport['polling_interval_seconds'] ?? 10, 10),
                'lookback_minutes' => $this->toInt($window['lookback_minutes'] ?? 120, 120),
                'max_points' => $this->toInt($window['max_points'] ?? 240, 240),
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  array<int, array{key: string, label: string, color: string}>  $series
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function buildTypedConfig(WidgetType $type, array $series, array $options, object $widget): array
    {
        $config = [
            'series' => $series,
            'transport' => [
                'use_websocket' => (bool) ($widget->use_websocket ?? true),
                'use_polling' => (bool) ($widget->use_polling ?? true),
                'polling_interval_seconds' => $this->clampInt($widget->polling_interval_seconds ?? 10, 10, 2, 300),
            ],
            'window' => [
                'lookback_minutes' => $this->clampInt($widget->lookback_minutes ?? 120, 120, 1, 129600),
                'max_points' => $this->clampInt($widget->max_points ?? 240, 240, 1, 1000),
            ],
        ];

        if ($type === WidgetType::BarChart) {
            $interval = is_string($options['bar_interval'] ?? null)
                ? strtolower(trim((string) $options['bar_interval']))
                : BarInterval::Hourly->value;

            $config['bar_interval'] = BarInterval::tryFrom($interval)?->value ?? BarInterval::Hourly->value;
        }

        if ($type === WidgetType::GaugeChart) {
            $style = is_string($options['gauge_style'] ?? null)
                ? strtolower(trim((string) $options['gauge_style']))
                : GaugeStyle::Classic->value;
            $minimum = is_numeric($options['gauge_min'] ?? null) ? (float) $options['gauge_min'] : 0;
            $maximum = is_numeric($options['gauge_max'] ?? null) ? (float) $options['gauge_max'] : 100;

            if ($maximum <= $minimum) {
                $maximum = $minimum + 1;
            }

            $config['gauge_style'] = GaugeStyle::tryFrom($style)?->value ?? GaugeStyle::Classic->value;
            $config['gauge_min'] = $minimum;
            $config['gauge_max'] = $maximum;
            $config['gauge_ranges'] = is_array($options['gauge_ranges'] ?? null)
                ? $options['gauge_ranges']
                : [
                    ['from' => 0, 'to' => 50, 'color' => '#10b981'],
                    ['from' => 50, 'to' => 80, 'color' => '#f59e0b'],
                    ['from' => 80, 'to' => 100, 'color' => '#ef4444'],
                ];
        }

        return $config;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{x: int, y: int, w: int, h: int, columns: int, card_height_px: int}
     */
    private function buildTypedLayout(array $options): array
    {
        $layout = is_array($options['layout'] ?? null) ? $options['layout'] : [];
        $fallbackW = $this->clampInt($options['grid_columns'] ?? 6, 6, 1, self::GRID_COLUMNS);
        $fallbackH = $this->clampInt((int) round($this->clampInt($options['card_height_px'] ?? 360, 360, 260, 900) / self::GRID_CELL_HEIGHT), 4, 2, 12);
        $layoutColumns = max(1, $this->toInt($options['layout_columns'] ?? 4, 4));
        $scaleFactor = self::GRID_COLUMNS / $layoutColumns;

        $x = $this->toInt($layout['x'] ?? 0, 0);
        $w = $this->toInt($layout['w'] ?? $fallbackW, $fallbackW);

        if ($layoutColumns !== self::GRID_COLUMNS) {
            $x = (int) round($x * $scaleFactor);
            $w = (int) round($w * $scaleFactor);
        }

        $h = $this->toInt($layout['h'] ?? $fallbackH, $fallbackH);

        return [
            'x' => max($x, 0),
            'y' => max($this->toInt($layout['y'] ?? 0, 0), 0),
            'w' => $this->clampInt($w, $fallbackW, 1, self::GRID_COLUMNS),
            'h' => $this->clampInt($h, $fallbackH, 2, 12),
            'columns' => self::GRID_COLUMNS,
            'card_height_px' => $this->clampInt($h, $fallbackH, 2, 12) * self::GRID_CELL_HEIGHT,
        ];
    }

    /**
     * @param  array<int, mixed>|mixed  $rawSeries
     * @return array<int, array{key: string, label: string, color: string}>
     */
    private function normalizeSeries(mixed $rawSeries): array
    {
        if (! is_array($rawSeries)) {
            return [];
        }

        $normalized = [];
        $seen = [];

        foreach ($rawSeries as $seriesEntry) {
            if (! is_array($seriesEntry)) {
                continue;
            }

            $key = is_string($seriesEntry['key'] ?? null)
                ? trim((string) $seriesEntry['key'])
                : '';

            if ($key === '' || in_array($key, $seen, true)) {
                continue;
            }

            $seen[] = $key;
            $normalized[] = [
                'key' => $key,
                'label' => is_string($seriesEntry['label'] ?? null) && trim((string) $seriesEntry['label']) !== ''
                    ? (string) $seriesEntry['label']
                    : $key,
                'color' => is_string($seriesEntry['color'] ?? null) && trim((string) $seriesEntry['color']) !== ''
                    ? (string) $seriesEntry['color']
                    : '#38bdf8',
            ];
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function toInt(mixed $value, int $default = 0): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    private function clampInt(mixed $value, int $default, int $min, int $max): int
    {
        return min(max($this->toInt($value, $default), $min), $max);
    }
};
