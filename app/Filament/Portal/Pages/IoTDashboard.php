<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use App\Domain\IoTDashboard\Enums\DashboardHistoryPreset;
use App\Domain\IoTDashboard\Models\IoTDashboard as IoTDashboardModel;
use App\Domain\Shared\Models\Organization;
use App\Filament\Admin\Pages\IoTDashboardSupport\WidgetBootstrapPayloadBuilder;
use App\Filament\Portal\Resources\IoTDashboards\IoTDashboardResource;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

/**
 * @property-read IoTDashboardModel|null $selectedDashboard
 * @property-read array<int, array<string, mixed>> $widgetBootstrapPayload
 */
class IoTDashboard extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected Width|string|null $maxContentWidth = 'full';

    protected string $view = 'filament.portal.pages.io-t-dashboard';

    public ?int $dashboardId = null;

    public function mount(): void
    {
        $requestedDashboardId = request()->integer('dashboard');
        $tenant = $this->tenant();

        if ($requestedDashboardId > 0) {
            abort_unless(
                IoTDashboardModel::query()
                    ->where('organization_id', $tenant->id)
                    ->whereKey($requestedDashboardId)
                    ->exists(),
                404,
            );

            $this->dashboardId = $requestedDashboardId;

            return;
        }

        $dashboardId = IoTDashboardModel::query()
            ->where('organization_id', $tenant->id)
            ->orderBy('name')
            ->value('id');

        $this->dashboardId = is_numeric($dashboardId) ? (int) $dashboardId : null;
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

        return __(':organization · Realtime device telemetry with polling fallback.', [
            'organization' => $this->tenant()->name,
        ]);
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
                ->url(IoTDashboardResource::getUrl(
                    panel: 'portal',
                    tenant: $this->tenant(),
                )),
            Action::make('historyRange')
                ->visible(fn (): bool => $this->selectedDashboard instanceof IoTDashboardModel)
                ->view('filament.admin.pages.io-t-dashboard.history-range-action', [
                    'historyPresets' => DashboardHistoryPreset::cases(),
                    'triggerLabel' => $defaultHistoryPreset->getLabel(),
                ]),
        ];
    }

    public function getSelectedDashboardProperty(): ?IoTDashboardModel
    {
        if (! is_int($this->dashboardId) || $this->dashboardId < 1) {
            return null;
        }

        $dashboard = IoTDashboardModel::query()
            ->where('organization_id', $this->tenant()->id)
            ->with([
                'organization:id,name',
                'widgets' => fn ($query) => $query
                    ->with([
                        'topic:id,label,address',
                        'device:id,uuid,name,organization_id,external_id,connection_state,last_seen_at,offline_deadline_at,presence_timeout_seconds',
                    ])
                    ->orderBy('sequence')
                    ->orderBy('id'),
            ])
            ->find($this->dashboardId);

        return $dashboard instanceof IoTDashboardModel ? $dashboard : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getWidgetBootstrapPayloadProperty(): array
    {
        if (! $this->selectedDashboard instanceof IoTDashboardModel) {
            return [];
        }

        return app(WidgetBootstrapPayloadBuilder::class)
            ->buildForPortal($this->selectedDashboard, $this->tenant());
    }

    private function tenant(): Organization
    {
        $tenant = Filament::getTenant();

        abort_unless($tenant instanceof Organization, 404);

        return $tenant;
    }
}
