<x-filament-panels::page>
    @push('styles')
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/gridstack@10.3.1/dist/gridstack.min.css">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/gridstack@10.3.1/dist/gridstack-extra.min.css">
    @endpush

    <meta name="enable-echo" content="true">

    <div class="iot-dashboard-shell">
        @if (! $this->selectedDashboard)
            <x-filament::section
                heading="No Dashboard Selected"
                description="Open a dashboard from the Dashboards list to visualize telemetry."
            >
                <div class="iot-empty-state">
                    No dashboard was selected. Use <strong>Dashboards</strong> and open one from the table action.
                </div>

                <div class="mt-4">
                    <x-filament::button
                        tag="a"
                        href="{{ \App\Filament\Portal\Resources\IoTDashboards\IoTDashboardResource::getUrl(panel: 'portal', tenant: \Filament\Facades\Filament::getTenant()) }}"
                    >
                        Open Dashboards
                    </x-filament::button>
                </div>
            </x-filament::section>
        @elseif ($this->selectedDashboard->widgets->isEmpty())
            <div class="iot-empty-state">
                @if ($this->canManageWidgets())
                    No widgets yet. Click <strong>Add Widget</strong> to start building this dashboard.
                @else
                    No widgets are configured for this dashboard.
                @endif
            </div>
        @else
            @php(
                $widgetLayouts = collect($this->widgetBootstrapPayload)
                    ->mapWithKeys(fn (array $widget): array => [(int) $widget['id'] => (array) ($widget['layout'] ?? [])])
                    ->all()
            )

            <div
                class="iot-dashboard-grid grid-stack"
                id="iot-dashboard-grid"
                data-read-only="{{ $this->canManageWidgets() ? 'false' : 'true' }}"
            >
                @foreach ($this->selectedDashboard->widgets as $widget)
                    @php($layout = $widgetLayouts[(int) $widget->id] ?? [])
                    @php($gridSpan = max(1, min(24, (int) data_get($layout, 'w', 6))))
                    @php($gridHeight = max(2, min(12, (int) data_get($layout, 'h', 4))))
                    @php($gridX = max(0, (int) data_get($layout, 'x', 0)))
                    @php($gridY = max(0, (int) data_get($layout, 'y', 0)))

                    <div
                        class="grid-stack-item"
                        wire:key="dashboard-widget-{{ $widget->id }}"
                        gs-id="{{ $widget->id }}"
                        gs-x="{{ $gridX }}"
                        gs-y="{{ $gridY }}"
                        gs-w="{{ $gridSpan }}"
                        gs-h="{{ $gridHeight }}"
                        @if (! $this->canManageWidgets())
                            gs-no-move="true"
                            gs-no-resize="true"
                        @endif
                    >
                        <article
                            class="iot-widget-card iot-widget-card--{{ str_replace('_', '-', (string) $widget->type) }} grid-stack-item-content"
                            data-widget-type="{{ $widget->type }}"
                        >
                            <header>
                                <div>
                                    <h3 class="iot-widget-title">{{ $widget->title }}</h3>
                                </div>

                                @if ($this->canManageWidgets())
                                    <div class="iot-widget-flags">
                                        {{ $this->widgetHeaderActionGroup((int) $widget->id) }}
                                    </div>
                                @endif
                            </header>

                            <div wire:ignore class="iot-widget-chart" id="iot-widget-chart-{{ $widget->id }}"></div>
                        </article>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <x-filament-actions::modals />

    @push('scripts')
        <script src="https://cdn.jsdelivr.net/npm/echarts@5/dist/echarts.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/gridstack@10.3.1/dist/gridstack-all.js"></script>
        <script>
            window.iotDashboardConfig = @js([
                'dashboard_id' => $this->selectedDashboard?->id,
                'organization_id' => $this->selectedDashboard?->organization_id,
                'default_history_preset' => $this->selectedDashboard?->default_history_preset?->value
                    ?? \App\Domain\IoTDashboard\Enums\DashboardHistoryPreset::Last6Hours->value,
                'snapshot_url' => $this->selectedDashboard
                    ? route('portal.iot-dashboard.dashboards.snapshots', [
                        'organization' => \Filament\Facades\Filament::getTenant(),
                        'dashboard' => $this->selectedDashboard,
                    ])
                    : null,
                'read_only' => ! $this->canManageWidgets(),
                'widgets' => $this->widgetBootstrapPayload,
            ]);
        </script>
        @if (! app()->runningUnitTests())
            @vite(['resources/css/iot-dashboard/page.css', 'resources/js/iot-dashboard/dashboard-page.js'])
        @endif
    @endpush
</x-filament-panels::page>
