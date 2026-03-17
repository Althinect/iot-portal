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
                description="Open a dashboard from the Dashboards list to configure widgets and visualize telemetry."
            >
                <div class="iot-empty-state">
                    No dashboard was selected. Use <strong>Dashboards</strong> and open one from the table action.
                </div>

                <div class="mt-4">
                    <x-filament::button tag="a" href="{{ \App\Filament\Admin\Resources\IoTDashboards\IoTDashboardResource::getUrl() }}">
                        Open Dashboards
                    </x-filament::button>
                </div>
            </x-filament::section>
        @else
            @php($historyPresets = \App\Domain\IoTDashboard\Enums\DashboardHistoryPreset::cases())

            <div class="iot-dashboard-toolbar">
                <div class="iot-history-range" data-iot-history-range>
                    <button
                        type="button"
                        class="iot-history-range__trigger"
                        data-iot-history-trigger
                        aria-haspopup="dialog"
                        aria-expanded="false"
                    >
                        <span class="iot-history-range__trigger-icon">
                            <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedClock" class="size-4" />
                        </span>
                        <span data-iot-history-trigger-label>Last 6 hours</span>
                    </button>

                    <div class="iot-history-range__popover" data-iot-history-popover hidden>
                        <div class="iot-history-range__popover-panel">
                            <div class="iot-history-range__pane">
                                <p class="iot-history-range__eyebrow">Time range</p>

                                <div data-iot-history-relative-pane>
                                    <label class="iot-history-range__field">
                                        <span>From</span>
                                        <input type="text" readonly data-iot-history-relative-from>
                                    </label>
                                    <label class="iot-history-range__field">
                                        <span>To</span>
                                        <input type="text" readonly data-iot-history-relative-until>
                                    </label>
                                </div>

                                <div data-iot-history-absolute-pane hidden>
                                    <label class="iot-history-range__field">
                                        <span>From</span>
                                        <input type="datetime-local" data-iot-history-absolute-from>
                                    </label>
                                    <label class="iot-history-range__field">
                                        <span>To</span>
                                        <input type="datetime-local" data-iot-history-absolute-until>
                                    </label>
                                </div>

                                <p class="iot-history-range__error" data-iot-history-error hidden></p>

                                <div class="iot-history-range__actions">
                                    <button type="button" class="iot-history-range__apply" data-iot-history-apply>
                                        Apply time range
                                    </button>
                                    <button type="button" class="iot-history-range__reset" data-iot-history-reset>
                                        Reset
                                    </button>
                                </div>
                            </div>

                            <div class="iot-history-range__quick-ranges">
                                <p class="iot-history-range__eyebrow">Quick ranges</p>

                                <div class="iot-history-range__quick-list">
                                    @foreach ($historyPresets as $historyPreset)
                                        <button
                                            type="button"
                                            class="iot-history-range__quick-button"
                                            data-iot-history-preset="{{ $historyPreset->value }}"
                                        >
                                            {{ $historyPreset->getLabel() }}
                                        </button>
                                    @endforeach

                                    <button
                                        type="button"
                                        class="iot-history-range__quick-button"
                                        data-iot-history-custom
                                    >
                                        Custom range
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="iot-history-range__footer">
                            <span class="iot-history-range__footer-label">Browser Time</span>
                            <span data-iot-history-timezone>—</span>
                        </div>
                    </div>
                </div>
            </div>

            @if ($this->selectedDashboard->widgets->isEmpty())
                <div class="iot-empty-state">
                    No widgets yet. Click <strong>Add Widget</strong>, choose a topic, then select the exact parameters.
                </div>
            @else
                @php(
                    $widgetLayouts = collect($this->widgetBootstrapPayload)
                        ->mapWithKeys(fn (array $widget): array => [(int) $widget['id'] => (array) ($widget['layout'] ?? [])])
                        ->all()
                )

                <div class="iot-dashboard-grid grid-stack" id="iot-dashboard-grid">
                    @foreach ($this->selectedDashboard->widgets as $widget)
                        @php($layout = $widgetLayouts[(int) $widget->id] ?? [])
                        @php($gridSpan = max(1, min(24, (int) data_get($layout, 'w', 6))))
                        @php($gridHeight = max(2, min(12, (int) data_get($layout, 'h', 4))) )
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
                        >
                            <article class="iot-widget-card grid-stack-item-content">
                                <header>
                                    <div>
                                        <h3 class="iot-widget-title">{{ $widget->title }}</h3>
                                    </div>

                                    <div class="iot-widget-flags">
                                        @php($bootstrapWidget = collect($this->widgetBootstrapPayload)->firstWhere('id', $widget->id))
                                        <x-filament::badge :color="(bool) data_get($bootstrapWidget, 'use_websocket', true) ? 'success' : 'gray'" size="sm">
                                            WS
                                        </x-filament::badge>
                                        <x-filament::badge :color="(bool) data_get($bootstrapWidget, 'use_polling', true) ? 'info' : 'gray'" size="sm">
                                            Poll
                                        </x-filament::badge>
                                        {{ $this->widgetHeaderActionGroup((int) $widget->id) }}
                                    </div>
                                </header>

                                <div wire:ignore class="iot-widget-chart" id="iot-widget-chart-{{ $widget->id }}"></div>
                            </article>
                        </div>
                    @endforeach
                </div>
            @endif
        @endif
    </div>

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
                    ? route('admin.iot-dashboard.dashboards.snapshots', ['dashboard' => $this->selectedDashboard])
                    : null,
                'widgets' => $this->widgetBootstrapPayload,
            ]);
        </script>
        @if (! app()->runningUnitTests())
            @vite(['resources/css/iot-dashboard/page.css', 'resources/js/iot-dashboard/dashboard-page.js'])
        @endif
    @endpush
</x-filament-panels::page>
