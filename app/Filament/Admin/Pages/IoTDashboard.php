<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domain\IoTDashboard\Enums\WidgetType;
use App\Domain\IoTDashboard\Models\IoTDashboard as IoTDashboardModel;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use App\Domain\Shared\Models\User;
use App\Filament\Admin\Pages\IoTDashboardSupport\Concerns\InteractsWithWidgets;
use App\Filament\Admin\Resources\IoTDashboards\IoTDashboardResource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

/**
 * @property-read IoTDashboardModel|null $selectedDashboard
 * @property-read array<int, array<string, mixed>> $widgetBootstrapPayload
 */
class IoTDashboard extends Page
{
    use InteractsWithWidgets;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected Width|string|null $maxContentWidth = 'full';

    protected string $view = 'filament.admin.pages.io-t-dashboard';

    public ?int $dashboardId = null;

    public function mount(): void
    {
        $requestedDashboardId = request()->integer('dashboard');

        /** @var User $user */
        $user = auth()->user();

        $this->dashboardId = $this->accessService()->resolveInitialDashboardId(
            requestedDashboardId: $requestedDashboardId > 0 ? $requestedDashboardId : null,
            user: $user,
        );
    }

    public function getTitle(): string
    {
        $dashboard = $this->selectedDashboard();

        return is_string($dashboard?->name) && trim($dashboard->name) !== ''
            ? $dashboard->name
            : __('IoT Dashboard');
    }

    public function getSubheading(): ?string
    {
        $dashboard = $this->selectedDashboard();

        if (! $dashboard instanceof IoTDashboardModel) {
            return __('Pick a dashboard from Dashboards to start plotting telemetry.');
        }

        $organizationName = $dashboard->organization?->name;
        $organizationName = is_string($organizationName) && trim($organizationName) !== ''
            ? $organizationName
            : 'Unknown Organization';

        return __(':organization · Realtime device telemetry with polling fallback.', [
            'organization' => $organizationName,
        ]);
    }

    public function getHeaderActions(): array
    {
        return [
            Action::make('dashboards')
                ->label('Dashboards')
                ->icon(Heroicon::OutlinedRectangleStack)
                ->url(IoTDashboardResource::getUrl()),

            ActionGroup::make([
                Action::make('addLineWidget')
                    ->label('Add Line Widget')
                    ->icon(Heroicon::OutlinedPresentationChartLine)
                    ->visible(fn (): bool => $this->selectedDashboard() instanceof IoTDashboardModel)
                    ->slideOver()
                    ->modalWidth('7xl')
                    ->schema(fn (): array => $this->selectedDashboard() instanceof IoTDashboardModel
                        ? $this->widgetFormSchemaFactory()->lineSchema($this->selectedDashboard())
                        : [])
                    ->action(function (array $data): void {
                        $this->createWidget(WidgetType::LineChart, $data);
                    }),

                Action::make('addBarWidget')
                    ->label('Add Bar Widget')
                    ->icon(Heroicon::OutlinedPresentationChartLine)
                    ->visible(fn (): bool => $this->selectedDashboard() instanceof IoTDashboardModel)
                    ->slideOver()
                    ->modalWidth('7xl')
                    ->schema(fn (): array => $this->selectedDashboard() instanceof IoTDashboardModel
                        ? $this->widgetFormSchemaFactory()->barSchema($this->selectedDashboard())
                        : [])
                    ->action(function (array $data): void {
                        $this->createWidget(WidgetType::BarChart, $data);
                    }),

                Action::make('addGaugeWidget')
                    ->label('Add Gauge Widget')
                    ->icon(Heroicon::OutlinedPresentationChartLine)
                    ->visible(fn (): bool => $this->selectedDashboard() instanceof IoTDashboardModel)
                    ->slideOver()
                    ->modalWidth('7xl')
                    ->schema(fn (): array => $this->selectedDashboard() instanceof IoTDashboardModel
                        ? $this->widgetFormSchemaFactory()->gaugeSchema($this->selectedDashboard())
                        : [])
                    ->action(function (array $data): void {
                        $this->createWidget(WidgetType::GaugeChart, $data);
                    }),

                Action::make('addStatusSummaryWidget')
                    ->label('Add Status Widget')
                    ->icon(Heroicon::OutlinedListBullet)
                    ->visible(fn (): bool => $this->selectedDashboard() instanceof IoTDashboardModel)
                    ->slideOver()
                    ->modalWidth('7xl')
                    ->schema(fn (): array => $this->selectedDashboard() instanceof IoTDashboardModel
                        ? $this->widgetFormSchemaFactory()->statusSummarySchema($this->selectedDashboard())
                        : [])
                    ->action(function (array $data): void {
                        $this->createWidget(WidgetType::StatusSummary, $data);
                    }),

                Action::make('addStateCardWidget')
                    ->label('Add State Card')
                    ->icon(Heroicon::OutlinedPresentationChartLine)
                    ->visible(fn (): bool => $this->selectedDashboard() instanceof IoTDashboardModel)
                    ->slideOver()
                    ->modalWidth('7xl')
                    ->schema(fn (): array => $this->selectedDashboard() instanceof IoTDashboardModel
                        ? $this->widgetFormSchemaFactory()->stateCardSchema($this->selectedDashboard())
                        : [])
                    ->action(function (array $data): void {
                        $this->createWidget(WidgetType::StateCard, $data);
                    }),

                Action::make('addStateTimelineWidget')
                    ->label('Add State Timeline')
                    ->icon(Heroicon::OutlinedPresentationChartLine)
                    ->visible(fn (): bool => $this->selectedDashboard() instanceof IoTDashboardModel)
                    ->slideOver()
                    ->modalWidth('7xl')
                    ->schema(fn (): array => $this->selectedDashboard() instanceof IoTDashboardModel
                        ? $this->widgetFormSchemaFactory()->stateTimelineSchema($this->selectedDashboard())
                        : [])
                    ->action(function (array $data): void {
                        $this->createWidget(WidgetType::StateTimeline, $data);
                    }),
            ])
                ->label('Add Widget')
                ->icon(Heroicon::OutlinedPlus)
                ->visible(fn (): bool => $this->selectedDashboard() instanceof IoTDashboardModel),
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
            ->schema(fn (): array => $this->selectedDashboard() instanceof IoTDashboardModel
                ? $this->widgetFormSchemaFactory()->editSchema($this->selectedDashboard())
                : [])
            ->fillForm(function (array $arguments): array {
                $widget = $this->resolveWidgetFromArguments($arguments);

                if (! $widget instanceof IoTDashboardWidget) {
                    return [];
                }

                return $this->widgetConfigFactory()->editFormData($widget);
            })
            ->action(function (array $data, array $arguments): void {
                $dashboard = $this->selectedDashboard();

                if (! $dashboard instanceof IoTDashboardModel) {
                    return;
                }

                $widget = $this->resolveWidgetFromArguments($arguments);

                if (! $widget instanceof IoTDashboardWidget) {
                    $this->warn('Widget not found');

                    return;
                }

                $normalizedData = $this->normalizeWidgetActionInput($widget->widgetType(), $data);
                $resolvedInput = $this->widgetFormOptionsService()->resolveInput($dashboard, $normalizedData);

                if ($resolvedInput === null) {
                    $this->warn('Invalid widget input', 'Verify device/topic/parameter selections.');

                    return;
                }

                $config = $this->widgetConfigFactory()->update(
                    type: $widget->widgetType(),
                    data: $normalizedData,
                    resolvedInput: $resolvedInput,
                    currentConfig: $widget->configObject(),
                );

                $layout = $this->widgetLayoutService()->buildLayout($normalizedData, $widget->layoutArray());

                $widget->forceFill([
                    'device_id' => $resolvedInput['device']->id,
                    'schema_version_topic_id' => $resolvedInput['topic']->id,
                    'title' => trim((string) $data['title']),
                    'config' => $config,
                    'layout' => $layout,
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
            ->modalHeading('Delete widget')
            ->modalDescription('This will remove the widget from the dashboard.')
            ->modalSubmitActionLabel('Delete widget')
            ->action(function (array $arguments): void {
                $widget = $this->resolveWidgetFromArguments($arguments);

                if (! $widget instanceof IoTDashboardWidget) {
                    $this->warn('Widget not found');

                    return;
                }

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
            ->tooltip('Widget actions')
            ->icon(Heroicon::OutlinedEllipsisVertical)
            ->iconButton()
            ->color('gray')
            ->size('sm')
            ->dropdownPlacement('bottom-end')
            ->livewire($this);
    }

    public function getSelectedDashboardProperty(): ?IoTDashboardModel
    {
        /** @var User $user */
        $user = auth()->user();

        return $this->accessService()->selectedDashboard($this->dashboardId, $user);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getWidgetBootstrapPayloadProperty(): array
    {
        $dashboard = $this->selectedDashboard();

        if (! $dashboard instanceof IoTDashboardModel) {
            return [];
        }

        return $this->widgetBootstrapPayloadBuilder()->build($dashboard);
    }
}
