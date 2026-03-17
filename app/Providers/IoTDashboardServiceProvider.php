<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\IoTDashboard\Application\WidgetRegistry;
use App\Domain\IoTDashboard\Contracts\WidgetDefinition;
use App\Domain\IoTDashboard\Widgets\BarChart\BarChartWidgetDefinition;
use App\Domain\IoTDashboard\Widgets\GaugeChart\GaugeChartWidgetDefinition;
use App\Domain\IoTDashboard\Widgets\LineChart\LineChartWidgetDefinition;
use App\Domain\IoTDashboard\Widgets\StateCard\StateCardWidgetDefinition;
use App\Domain\IoTDashboard\Widgets\StateTimeline\StateTimelineWidgetDefinition;
use App\Domain\IoTDashboard\Widgets\StatusSummary\StatusSummaryWidgetDefinition;
use Illuminate\Support\ServiceProvider;

class IoTDashboardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LineChartWidgetDefinition::class);
        $this->app->singleton(BarChartWidgetDefinition::class);
        $this->app->singleton(GaugeChartWidgetDefinition::class);
        $this->app->singleton(StatusSummaryWidgetDefinition::class);
        $this->app->singleton(StateCardWidgetDefinition::class);
        $this->app->singleton(StateTimelineWidgetDefinition::class);

        $this->app->tag([
            LineChartWidgetDefinition::class,
            BarChartWidgetDefinition::class,
            GaugeChartWidgetDefinition::class,
            StatusSummaryWidgetDefinition::class,
            StateCardWidgetDefinition::class,
            StateTimelineWidgetDefinition::class,
        ], WidgetDefinition::class);

        $this->app->singleton(WidgetRegistry::class, function ($app): WidgetRegistry {
            /** @var iterable<int, WidgetDefinition> $definitions */
            $definitions = $app->tagged(WidgetDefinition::class);

            return new WidgetRegistry($definitions);
        });
    }

    public function boot(): void {}
}
