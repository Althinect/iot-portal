<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use App\Domain\IoTDashboard\Enums\DashboardHistoryPreset;
use App\Domain\IoTDashboard\Enums\WidgetType;
use App\Domain\IoTDashboard\Models\IoTDashboard as IoTDashboardModel;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use App\Domain\Shared\Models\Organization;
use App\Filament\Admin\Pages\IoTDashboardSupport\Concerns\InteractsWithWidgets;
use App\Filament\Portal\Resources\IoTDashboards\IoTDashboardResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * @property-read IoTDashboardModel|null $selectedDashboard
 * @property-read array<int, array<string, mixed>> $widgetBootstrapPayload
 */
class IoTDashboard extends Page
{
    use InteractsWithWidgets;

    protected static bool $shouldRegisterNavigation = false;

    protected Width|string|null $maxContentWidth = 'full';

    protected string $view = 'filament.portal.pages.io-t-dashboard';

    public ?int $dashboardId = null;

    public function mount(): void
    {
        $requestedDashboardId = request()->integer('dashboard');

        if ($requestedDashboardId < 1) {
            return;
        }

        $dashboard = IoTDashboardResource::getEloquentQuery()
            ->whereKey($requestedDashboardId)
            ->first();

        abort_unless($dashboard instanceof IoTDashboardModel, 404);
        Gate::authorize('view', $dashboard);

        $this->dashboardId = (int) $dashboard->id;
    }

    public function getTitle(): string
    {
        return $this->selectedDashboard?->name ?? __('IoT Dashboard');
    }

    public function getSubheading(): ?string
    {
        if (! $this->selectedDashboard instanceof IoTDashboardModel) {
            return __('Open a dashboard from the Dashboards list to view telemetry.');
        }

        return $this->canManageWidgets()
            ? __('Drag, resize, add, and configure widgets for your organization.')
            : __('Realtime device telemetry with polling fallback.');
    }

    public function getHeaderActions(): array
    {
        $configuredHistoryPreset = $this->selectedDashboard?->default_history_preset;
        $defaultHistoryPreset = $configuredHistoryPreset instanceof DashboardHistoryPreset
            ? $configuredHistoryPreset
            : DashboardHistoryPreset::Last6Hours;

        return [
            Action::make('dashboards')
                ->label('Dashboards')
                ->icon(Heroicon::OutlinedRectangleStack)
                ->url(IoTDashboardResource::getUrl()),
            ActionGroup::make($this->addWidgetActions())
                ->label('Add Widget')
                ->icon(Heroicon::OutlinedPlus)
                ->visible(fn (): bool => $this->canManageWidgets()),
            Action::make('historyRange')
                ->visible(fn (): bool => $this->selectedDashboard instanceof IoTDashboardModel)
                ->view('filament.admin.pages.io-t-dashboard.history-range-action', [
                    'historyPresets' => DashboardHistoryPreset::cases(),
                    'triggerLabel' => $defaultHistoryPreset->getLabel(),
                ]),
        ];
    }

    public function editWidgetAction(): Action
    {
        return Action::make('editWidget')
            ->label('Edit widget')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->slideOver()
            ->modalWidth('7xl')
            ->schema(fn (): array => $this->selectedDashboard instanceof IoTDashboardModel
                ? $this->widgetFormSchemaFactory()->editSchema($this->selectedDashboard)
                : [])
            ->fillForm(function (array $arguments): array {
                $widget = $this->resolveWidgetFromArguments($arguments);

                return $widget instanceof IoTDashboardWidget
                    ? $this->widgetConfigFactory()->editFormData($widget)
                    : [];
            })
            ->action(function (array $data, array $arguments): void {
                $dashboard = $this->selectedDashboard;
                $widget = $this->resolveWidgetFromArguments($arguments);

                abort_unless($dashboard instanceof IoTDashboardModel && $widget instanceof IoTDashboardWidget, 404);
                Gate::authorize('update', $dashboard);
                Gate::authorize('update', $widget);

                $normalizedData = $this->normalizeWidgetActionInput($widget->widgetType(), $data);
                $resolvedInput = $this->widgetFormOptionsService()->resolveInput($dashboard, $normalizedData);

                if ($resolvedInput === null) {
                    $this->warn('Invalid widget input', 'Verify device, channel, and parameter selections.');

                    return;
                }

                $widget->forceFill([
                    'device_id' => $resolvedInput['device']->id,
                    'device_channel_id' => $resolvedInput['topic']->id,
                    'title' => trim((string) ($data['title'] ?? '')),
                    'config' => $this->widgetConfigFactory()->update(
                        type: $widget->widgetType(),
                        data: $normalizedData,
                        resolvedInput: $resolvedInput,
                        currentConfig: $widget->configObject(),
                    ),
                    'layout' => $this->widgetLayoutService()->buildLayout($normalizedData, $widget->layoutArray()),
                ])->save();

                Notification::make()->title('Widget updated')->success()->send();
                $this->refreshDashboardComputedProperties();
                $this->dispatchWidgetBootstrapEvent();
            });
    }

    public function deleteWidgetAction(): Action
    {
        return Action::make('deleteWidget')
            ->label('Delete widget')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->action(function (array $arguments): void {
                $widget = $this->resolveWidgetFromArguments($arguments);
                abort_unless($widget instanceof IoTDashboardWidget, 404);
                Gate::authorize('delete', $widget);

                $widget->delete();

                Notification::make()->title('Widget removed')->success()->send();
                $this->refreshDashboardComputedProperties();
                $this->dispatchWidgetBootstrapEvent();
            });
    }

    public function duplicateWidgetAction(): Action
    {
        return Action::make('duplicateWidget')
            ->label('Duplicate widget')
            ->icon(Heroicon::OutlinedSquare2Stack)
            ->color('gray')
            ->action(function (array $arguments): void {
                $dashboard = $this->selectedDashboard;
                $widget = $this->resolveWidgetFromArguments($arguments);

                abort_unless($dashboard instanceof IoTDashboardModel && $widget instanceof IoTDashboardWidget, 404);
                Gate::authorize('update', $dashboard);
                Gate::authorize('create', IoTDashboardWidget::class);
                $this->duplicateWidget($arguments);
            });
    }

    public function widgetHeaderActionGroup(int $widgetId): ActionGroup
    {
        return ActionGroup::make([
            ($this->editWidgetAction())(['widget' => $widgetId])->grouped(),
            ($this->duplicateWidgetAction())(['widget' => $widgetId])->grouped(),
            ($this->deleteWidgetAction())(['widget' => $widgetId])->grouped(),
        ])
            ->label('Widget actions')
            ->icon(Heroicon::OutlinedEllipsisVertical)
            ->iconButton()
            ->color('gray')
            ->size('sm')
            ->dropdownPlacement('bottom-end')
            ->dropdownTeleport()
            ->livewire($this);
    }

    public function getSelectedDashboardProperty(): ?IoTDashboardModel
    {
        if (! is_int($this->dashboardId) || $this->dashboardId < 1) {
            return null;
        }

        $dashboard = IoTDashboardResource::getEloquentQuery()
            ->with([
                'widgets.device',
                'widgets.topic.parameters',
            ])
            ->whereKey($this->dashboardId)
            ->first();

        if (! $dashboard instanceof IoTDashboardModel || Gate::denies('view', $dashboard)) {
            return null;
        }

        return $dashboard;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getWidgetBootstrapPayloadProperty(): array
    {
        if (! $this->selectedDashboard instanceof IoTDashboardModel) {
            return [];
        }

        return $this->widgetBootstrapPayloadBuilder()->buildForPortal(
            $this->selectedDashboard,
            $this->tenant(),
            readOnly: ! $this->canManageWidgets(),
        );
    }

    public function canManageWidgets(): bool
    {
        return $this->selectedDashboard instanceof IoTDashboardModel
            && Gate::allows('update', $this->selectedDashboard)
            && Gate::allows('create', IoTDashboardWidget::class);
    }

    /**
     * @return array<int, Action>
     */
    private function addWidgetActions(): array
    {
        return [
            $this->addWidgetAction('addLineWidget', 'Add Line Widget', WidgetType::LineChart, 'lineSchema'),
            $this->addWidgetAction('addBarWidget', 'Add Bar Widget', WidgetType::BarChart, 'barSchema'),
            $this->addWidgetAction('addGaugeWidget', 'Add Gauge Widget', WidgetType::GaugeChart, 'gaugeSchema'),
            $this->addWidgetAction('addStatusSummaryWidget', 'Add Status Widget', WidgetType::StatusSummary, 'statusSummarySchema'),
            $this->addWidgetAction('addStateCardWidget', 'Add State Card', WidgetType::StateCard, 'stateCardSchema'),
            $this->addWidgetAction('addStateTimelineWidget', 'Add State Timeline', WidgetType::StateTimeline, 'stateTimelineSchema'),
            $this->addWidgetAction('addThresholdStatusCardWidget', 'Add Threshold Status', WidgetType::ThresholdStatusCard, 'thresholdStatusCardSchema'),
            $this->addWidgetAction('addStenterUtilizationWidget', 'Add Stenter Widget', WidgetType::StenterUtilization, 'stenterUtilizationSchema'),
            $this->addWidgetAction('addCompressorUtilizationWidget', 'Add Compressor Widget', WidgetType::CompressorUtilization, 'compressorUtilizationSchema'),
            $this->addWidgetAction('addSteamMeterWidget', 'Add Steam Meter Widget', WidgetType::SteamMeter, 'steamMeterSchema'),
        ];
    }

    private function addWidgetAction(
        string $name,
        string $label,
        WidgetType $type,
        string $schemaMethod,
    ): Action {
        return Action::make($name)
            ->label($label)
            ->icon(Heroicon::OutlinedPresentationChartLine)
            ->slideOver()
            ->modalWidth('7xl')
            ->schema(function () use ($schemaMethod): array {
                if (! $this->selectedDashboard instanceof IoTDashboardModel) {
                    return [];
                }

                return $this->widgetFormSchemaFactory()->{$schemaMethod}($this->selectedDashboard);
            })
            ->action(function (array $data) use ($type): void {
                abort_unless($this->selectedDashboard instanceof IoTDashboardModel, 404);
                Gate::authorize('update', $this->selectedDashboard);
                Gate::authorize('create', IoTDashboardWidget::class);
                $this->createWidget($type, $data);
            });
    }

    private function tenant(): Organization
    {
        $tenant = Filament::getTenant();
        abort_unless($tenant instanceof Organization, 404);

        return $tenant;
    }
}
