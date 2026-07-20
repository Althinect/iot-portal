<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domain\Automation\Models\AutomationThresholdPolicy;
use App\Domain\Automation\Services\ThresholdPolicyWorkflowProjector;
use App\Domain\IoTDashboard\Enums\DashboardHistoryPreset;
use App\Domain\IoTDashboard\Enums\WidgetType;
use App\Domain\IoTDashboard\Models\IoTDashboard as IoTDashboardModel;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use App\Domain\Shared\Models\User;
use App\Filament\Admin\Pages\IoTDashboardSupport\Concerns\InteractsWithWidgets;
use App\Filament\Admin\Resources\AutomationThresholdPolicies\AutomationThresholdPolicyResource;
use App\Filament\Admin\Resources\AutomationThresholdPolicies\Schemas\AutomationThresholdPolicyForm;
use App\Filament\Admin\Resources\IoTDashboards\IoTDashboardResource;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\On;

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
        $configuredHistoryPreset = data_get($this->selectedDashboard(), 'default_history_preset');
        $defaultHistoryPreset = $configuredHistoryPreset instanceof DashboardHistoryPreset
            ? $configuredHistoryPreset
            : (is_string($configuredHistoryPreset)
                ? DashboardHistoryPreset::tryFrom($configuredHistoryPreset)
                : null);

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

                Action::make('addThresholdStatusCardWidget')
                    ->label('Add Threshold Status')
                    ->icon(Heroicon::OutlinedSquares2x2)
                    ->visible(fn (): bool => $this->selectedDashboard() instanceof IoTDashboardModel)
                    ->slideOver()
                    ->modalWidth('7xl')
                    ->schema(fn (): array => $this->selectedDashboard() instanceof IoTDashboardModel
                        ? $this->widgetFormSchemaFactory()->thresholdStatusCardSchema($this->selectedDashboard())
                        : [])
                    ->action(function (array $data): void {
                        $this->createWidget(WidgetType::ThresholdStatusCard, $data);
                    }),

                Action::make('addStenterUtilizationWidget')
                    ->label('Add Stenter Widget')
                    ->icon(Heroicon::OutlinedPresentationChartBar)
                    ->visible(fn (): bool => $this->selectedDashboard() instanceof IoTDashboardModel)
                    ->slideOver()
                    ->modalWidth('7xl')
                    ->schema(fn (): array => $this->selectedDashboard() instanceof IoTDashboardModel
                        ? $this->widgetFormSchemaFactory()->stenterUtilizationSchema($this->selectedDashboard())
                        : [])
                    ->action(function (array $data): void {
                        $this->createWidget(WidgetType::StenterUtilization, $data);
                    }),

                Action::make('addCompressorUtilizationWidget')
                    ->label('Add Compressor Widget')
                    ->icon(Heroicon::OutlinedPresentationChartBar)
                    ->visible(fn (): bool => $this->selectedDashboard() instanceof IoTDashboardModel)
                    ->slideOver()
                    ->modalWidth('7xl')
                    ->schema(fn (): array => $this->selectedDashboard() instanceof IoTDashboardModel
                        ? $this->widgetFormSchemaFactory()->compressorUtilizationSchema($this->selectedDashboard())
                        : [])
                    ->action(function (array $data): void {
                        $this->createWidget(WidgetType::CompressorUtilization, $data);
                    }),

                Action::make('addSteamMeterWidget')
                    ->label('Add Steam Meter Widget')
                    ->icon(Heroicon::OutlinedPresentationChartBar)
                    ->visible(fn (): bool => $this->selectedDashboard() instanceof IoTDashboardModel)
                    ->slideOver()
                    ->modalWidth('7xl')
                    ->schema(fn (): array => $this->selectedDashboard() instanceof IoTDashboardModel
                        ? $this->widgetFormSchemaFactory()->steamMeterSchema($this->selectedDashboard())
                        : [])
                    ->action(function (array $data): void {
                        $this->createWidget(WidgetType::SteamMeter, $data);
                    }),
            ])
                ->label('Add Widget')
                ->icon(Heroicon::OutlinedPlus)
                ->visible(fn (): bool => $this->selectedDashboard() instanceof IoTDashboardModel),

            Action::make('historyRange')
                ->visible(fn (): bool => $this->selectedDashboard() instanceof IoTDashboardModel)
                ->view('filament.admin.pages.io-t-dashboard.history-range-action', [
                    'historyPresets' => DashboardHistoryPreset::cases(),
                    'triggerLabel' => $defaultHistoryPreset?->getLabel()
                        ?? DashboardHistoryPreset::Last6Hours->getLabel(),
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
                    'device_channel_id' => $resolvedInput['topic']->id,
                    'title' => trim((string) $data['title']),
                    'config' => $config,
                    'layout' => $layout,
                ])->save();

                Notification::make()->title('Widget updated')->success()->send();

                $this->refreshDashboardComputedProperties();
                $this->dispatchWidgetBootstrapEvent();
            });
    }

    public function editThresholdPolicyAction(): Action
    {
        return Action::make('editThresholdPolicy')
            ->label('Edit threshold policy')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->model(AutomationThresholdPolicy::class)
            ->record(fn (Action $action): ?AutomationThresholdPolicy => $this->resolveThresholdPolicyFromArguments($action->getArguments()))
            ->slideOver()
            ->modalWidth('7xl')
            ->modalHeading('Edit threshold policy')
            ->schema(fn (): array => $this->thresholdPolicyFormComponents())
            ->fillForm(function (array $arguments): array {
                $policy = $this->resolveThresholdPolicyFromArguments($arguments);

                if (! $policy instanceof AutomationThresholdPolicy) {
                    return [];
                }

                return $this->thresholdPolicyFormData($policy);
            })
            ->action(function (array $data, array $arguments): void {
                $policy = $this->resolveThresholdPolicyFromArguments($arguments);

                if (! $policy instanceof AutomationThresholdPolicy) {
                    $this->warn('Threshold policy not found');

                    return;
                }

                $preparedData = AutomationThresholdPolicyResource::prepareThresholdPolicyFormData(
                    $data,
                    is_numeric($policy->getKey()) ? (int) $policy->getKey() : null,
                );

                $policy->fill($preparedData);
                $policy->save();

                app(ThresholdPolicyWorkflowProjector::class)->sync($policy);

                Notification::make()->title('Threshold policy updated')->success()->send();

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
            ->dropdownTeleport()
            ->livewire($this);
    }

    #[On('iot-dashboard-edit-threshold-policy')]
    public function openThresholdPolicyEditor(int $policyId): void
    {
        $policy = $this->resolveThresholdPolicyById($policyId);

        if (! $policy instanceof AutomationThresholdPolicy) {
            $this->warn('Threshold policy not found');

            return;
        }

        $this->mountAction('editThresholdPolicy', ['policy' => $policy->id]);
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

    /**
     * @return array<int, mixed>
     */
    private function thresholdPolicyFormComponents(): array
    {
        return AutomationThresholdPolicyForm::configure(Schema::make())
            ->getComponents();
    }

    /**
     * @return array<string, mixed>
     */
    private function thresholdPolicyFormData(AutomationThresholdPolicy $policy): array
    {
        return AutomationThresholdPolicyResource::prepareThresholdPolicyFormDataBeforeFill(
            $policy->attributesToArray(),
            $policy,
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function resolveThresholdPolicyFromArguments(array $arguments): ?AutomationThresholdPolicy
    {
        $policyId = is_numeric($arguments['policy'] ?? null)
            ? (int) $arguments['policy']
            : null;

        if ($policyId === null) {
            return null;
        }

        return $this->resolveThresholdPolicyById($policyId);
    }

    private function resolveThresholdPolicyById(int $policyId): ?AutomationThresholdPolicy
    {
        $dashboard = $this->selectedDashboard();

        if (! $dashboard instanceof IoTDashboardModel) {
            return null;
        }

        return AutomationThresholdPolicy::query()
            ->where('organization_id', (int) $dashboard->organization_id)
            ->find($policyId);
    }
}
