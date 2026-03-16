<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\IoTDashboardSupport\Concerns;

use App\Domain\IoTDashboard\Enums\WidgetType;
use App\Domain\IoTDashboard\Models\IoTDashboard as IoTDashboardModel;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use App\Filament\Admin\Pages\IoTDashboardSupport\DashboardAccessService;
use App\Filament\Admin\Pages\IoTDashboardSupport\WidgetBootstrapPayloadBuilder;
use App\Filament\Admin\Pages\IoTDashboardSupport\WidgetConfigFactory;
use App\Filament\Admin\Pages\IoTDashboardSupport\WidgetFormOptionsService;
use App\Filament\Admin\Pages\IoTDashboardSupport\WidgetFormSchemaFactory;
use App\Filament\Admin\Pages\IoTDashboardSupport\WidgetLayoutService;
use Filament\Notifications\Notification;

trait InteractsWithWidgets
{
    /**
     * @param  array<string, mixed>  $data
     */
    private function createWidget(WidgetType $type, array $data): void
    {
        $dashboard = $this->selectedDashboard();

        if (! $dashboard instanceof IoTDashboardModel) {
            $this->warn('Select a dashboard first');

            return;
        }

        $normalizedData = $this->normalizeWidgetActionInput($type, $data);
        $resolvedInput = $this->widgetFormOptionsService()->resolveInput($dashboard, $normalizedData);

        if ($resolvedInput === null) {
            $this->warn('Invalid widget input', 'Verify device/topic/parameter selections.');

            return;
        }

        $config = $this->widgetConfigFactory()->create(
            type: $type,
            data: $normalizedData,
            series: $resolvedInput['series'],
        );

        $maxSequence = $dashboard->widgets()->max('sequence');
        $nextSequence = (is_numeric($maxSequence) ? (int) $maxSequence : 0) + 1;

        IoTDashboardWidget::query()->create([
            'iot_dashboard_id' => $dashboard->id,
            'device_id' => $resolvedInput['device']->id,
            'schema_version_topic_id' => $resolvedInput['topic']->id,
            'type' => $type->value,
            'title' => $this->resolveTitle($data),
            'config' => $config,
            'layout' => $this->widgetLayoutService()->buildLayout($normalizedData),
            'sequence' => $nextSequence,
        ]);

        Notification::make()
            ->title(match ($type) {
                WidgetType::LineChart => 'Line widget added',
                WidgetType::BarChart => 'Bar widget added',
                WidgetType::GaugeChart => 'Gauge widget added',
                WidgetType::StateCard => 'State card added',
                WidgetType::StateTimeline => 'State timeline added',
            })
            ->success()
            ->send();

        $this->refreshDashboardComputedProperties();
        $this->dispatchWidgetBootstrapEvent();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeWidgetActionInput(WidgetType $type, array $data): array
    {
        if ($type === WidgetType::LineChart) {
            return $data;
        }

        $parameterKey = is_string($data['parameter_key'] ?? null)
            ? trim((string) $data['parameter_key'])
            : null;

        return [
            ...$data,
            'parameter_keys' => $parameterKey === null || $parameterKey === '' ? [] : [$parameterKey],
            'state_mappings' => in_array($type, [WidgetType::StateCard, WidgetType::StateTimeline], true)
                ? $this->resolveStateMappingsFromInput($data, $parameterKey)
                : ($data['state_mappings'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array{value: string, label: string, color: string}>
     */
    private function resolveStateMappingsFromInput(array $data, ?string $parameterKey): array
    {
        if (is_array($data['state_mappings'] ?? null) && $data['state_mappings'] !== []) {
            /** @var array<int, array{value: string, label: string, color: string}> $stateMappings */
            $stateMappings = $data['state_mappings'];

            return $stateMappings;
        }

        return $this->widgetFormOptionsService()->defaultStateMappings(
            $data['schema_version_topic_id'] ?? null,
            $parameterKey,
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function resolveWidgetFromArguments(array $arguments): ?IoTDashboardWidget
    {
        $dashboard = $this->selectedDashboard();
        $widgetId = is_numeric($arguments['widget'] ?? null)
            ? (int) $arguments['widget']
            : null;

        if (! $dashboard instanceof IoTDashboardModel || $widgetId === null) {
            return null;
        }

        return IoTDashboardWidget::query()
            ->where('iot_dashboard_id', $dashboard->id)
            ->whereKey($widgetId)
            ->first();
    }

    private function dispatchWidgetBootstrapEvent(): void
    {
        $this->dispatch('iot-dashboard-widgets-updated', widgets: $this->getWidgetBootstrapPayloadProperty());
    }

    private function refreshDashboardComputedProperties(): void
    {
        unset($this->{'selectedDashboard'});
        unset($this->{'widgetBootstrapPayload'});
    }

    private function selectedDashboard(): ?IoTDashboardModel
    {
        $dashboard = $this->selectedDashboard;

        return $dashboard instanceof IoTDashboardModel ? $dashboard : null;
    }

    private function warn(string $title, ?string $body = null): void
    {
        $notification = Notification::make()->title($title)->warning();

        if (is_string($body) && trim($body) !== '') {
            $notification->body($body);
        }

        $notification->send();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveTitle(array $data): string
    {
        $title = $data['title'] ?? null;

        return is_string($title) ? trim($title) : '';
    }

    private function accessService(): DashboardAccessService
    {
        return app(DashboardAccessService::class);
    }

    private function widgetFormSchemaFactory(): WidgetFormSchemaFactory
    {
        return app(WidgetFormSchemaFactory::class);
    }

    private function widgetFormOptionsService(): WidgetFormOptionsService
    {
        return app(WidgetFormOptionsService::class);
    }

    private function widgetConfigFactory(): WidgetConfigFactory
    {
        return app(WidgetConfigFactory::class);
    }

    private function widgetLayoutService(): WidgetLayoutService
    {
        return app(WidgetLayoutService::class);
    }

    private function widgetBootstrapPayloadBuilder(): WidgetBootstrapPayloadBuilder
    {
        return app(WidgetBootstrapPayloadBuilder::class);
    }
}
