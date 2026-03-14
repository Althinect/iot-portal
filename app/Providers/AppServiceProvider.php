<?php

declare(strict_types=1);

namespace App\Providers;

use App\Database\TimescalePostgresConnection;
use App\Domain\Automation\Contracts\TriggerMatcher;
use App\Domain\Automation\Listeners\QueueTelemetryAutomationRuns;
use App\Domain\Automation\Models\AutomationWorkflow;
use App\Domain\Automation\Services\DatabaseTriggerMatcher;
use App\Domain\DataIngestion\Contracts\AnalyticsPublisher;
use App\Domain\DataIngestion\Contracts\HotStateStore;
use App\Domain\DataIngestion\Listeners\QueueTelemetryAnalyticsPublishes;
use App\Domain\DataIngestion\Listeners\QueueTelemetryHotStateWrites;
use App\Domain\DataIngestion\Services\DeviceTelemetryTopicResolver;
use App\Domain\DataIngestion\Services\NatsAnalyticsPublisher;
use App\Domain\DataIngestion\Services\NatsKvHotStateStore;
use App\Domain\DataIngestion\Services\TelemetrySchemaMetadataCache;
use App\Domain\DeviceManagement\Publishing\Mqtt\MqttCommandPublisher;
use App\Domain\DeviceManagement\Publishing\Mqtt\PhpMqttCommandPublisher;
use App\Domain\DeviceManagement\Publishing\Nats\BasisNatsDeviceStateStore;
use App\Domain\DeviceManagement\Publishing\Nats\BasisNatsPublisherFactory;
use App\Domain\DeviceManagement\Publishing\Nats\NatsDeviceStateStore;
use App\Domain\DeviceManagement\Publishing\Nats\NatsPublisherFactory;
use App\Domain\IoTDashboard\Models\IoTDashboard;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use App\Domain\Reporting\Models\ReportRun;
use App\Domain\Shared\Models\User;
use App\Domain\Shared\Services\HorizonRuntimeConfigurator;
use App\Domain\Shared\Services\RuntimeSettingManager;
use App\Domain\Shared\Services\RuntimeSettingRegistry;
use App\Events\TelemetryReceived;
use App\Policies\AutomationWorkflowPolicy;
use App\Policies\IoTDashboardPolicy;
use App\Policies\IoTDashboardWidgetPolicy;
use App\Policies\ReportRunPolicy;
use Filament\Events\TenantSet;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\FileUpload;
use Filament\Infolists\Components\ImageEntry;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Table;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        Connection::resolverFor('pgsql', function ($connection, $database, $prefix, $config) {
            return new TimescalePostgresConnection($connection, $database, $prefix, $config);
        });

        $this->app->bind(NatsPublisherFactory::class, BasisNatsPublisherFactory::class);
        $this->app->bind(NatsDeviceStateStore::class, BasisNatsDeviceStateStore::class);
        $this->app->bind(MqttCommandPublisher::class, PhpMqttCommandPublisher::class);
        $this->app->bind(HotStateStore::class, NatsKvHotStateStore::class);
        $this->app->bind(AnalyticsPublisher::class, NatsAnalyticsPublisher::class);
        $this->app->bind(TriggerMatcher::class, DatabaseTriggerMatcher::class);
        $this->app->singleton(RuntimeSettingRegistry::class);
        $this->app->scoped(RuntimeSettingManager::class);
        $this->app->singleton(HorizonRuntimeConfigurator::class);
        $this->app->singleton(DeviceTelemetryTopicResolver::class);
        $this->app->singleton(TelemetrySchemaMetadataCache::class);

        if ($this->app->environment('local')) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
            $this->app->register(TelescopeServiceProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Preserve v3 behavior for file visibility (files default to public instead of private)
        FileUpload::configureUsing(fn (FileUpload $fileUpload) => $fileUpload
            ->visibility('public'));

        ImageColumn::configureUsing(fn (ImageColumn $imageColumn) => $imageColumn
            ->visibility('public'));

        ImageEntry::configureUsing(fn (ImageEntry $imageEntry) => $imageEntry
            ->visibility('public'));

        // Preserve v3 behavior for table filters and pagination
        Table::configureUsing(fn (Table $table) => $table
            ->deferFilters(false)
            ->paginationPageOptions([5, 10, 25, 50, 'all']));

        // Preserve v3 behavior for layout components (span full width)
        Fieldset::configureUsing(fn (Fieldset $fieldset) => $fieldset
            ->columnSpanFull());

        Grid::configureUsing(fn (Grid $grid) => $grid
            ->columnSpanFull());

        Section::configureUsing(fn (Section $section) => $section
            ->columnSpanFull());

        // Preserve v3 behavior for unique validation (don't ignore current record by default)
        Field::configureUsing(fn (Field $field) => $field
            ->uniqueValidationIgnoresRecordByDefault(false));

        Gate::before(function (User $user, $ability) {
            return $user->isSuperAdmin() ? true : null;
        });

        Gate::policy(AutomationWorkflow::class, AutomationWorkflowPolicy::class);
        Gate::policy(IoTDashboard::class, IoTDashboardPolicy::class);
        Gate::policy(IoTDashboardWidget::class, IoTDashboardWidgetPolicy::class);
        Gate::policy(ReportRun::class, ReportRunPolicy::class);

        Event::listen(TenantSet::class, function (): void {
            setPermissionsTeamId(Filament::getTenant()->id);

            Cache::store(config('permission.cache.store') === 'default' ? null : config('permission.cache.store'))
                ->forget(config('permission.cache.key'));
        });

        Event::listen(TelemetryReceived::class, QueueTelemetryAutomationRuns::class);
        Event::listen(TelemetryReceived::class, QueueTelemetryHotStateWrites::class);
        Event::listen(TelemetryReceived::class, QueueTelemetryAnalyticsPublishes::class);

        if ($this->shouldApplyHorizonRuntimeConfiguration()) {
            $this->app->make(HorizonRuntimeConfigurator::class)->apply();
        }
    }

    private function shouldApplyHorizonRuntimeConfiguration(): bool
    {
        if (! $this->app->runningInConsole()) {
            return false;
        }

        $command = $_SERVER['argv'][1] ?? null;

        return is_string($command) && str_starts_with($command, 'horizon');
    }
}
