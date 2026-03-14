<x-filament-panels::page>
    @php
        $recentPublishFailures = \App\Domain\Telemetry\Models\DeviceTelemetryLog::query()
            ->with(['device:id,name,external_id', 'ingestionMessage:id,error_summary'])
            ->where('processing_state', 'publish_failed')
            ->latest('recorded_at')
            ->limit(10)
            ->get();

        $publishFailureCount = \App\Domain\Telemetry\Models\DeviceTelemetryLog::query()
            ->where('processing_state', 'publish_failed')
            ->where('recorded_at', '>=', now()->subHour())
            ->count();

        $processedCount = \App\Domain\Telemetry\Models\DeviceTelemetryLog::query()
            ->where('processing_state', 'processed')
            ->where('recorded_at', '>=', now()->subHour())
            ->count();

        $recentTotal = $publishFailureCount + $processedCount;
        $failureRate = $recentTotal > 0 ? round(($publishFailureCount / $recentTotal) * 100, 1) : 0.0;

        $healthBadge = match (true) {
            $failureRate >= 25 => ['label' => 'High Risk', 'color' => 'danger'],
            $failureRate >= 10 => ['label' => 'Watch', 'color' => 'warning'],
            default => ['label' => 'Stable', 'color' => 'success'],
        };
    @endphp

    <style>
        .tv-health-shell {
            background: linear-gradient(150deg, rgba(16, 24, 40, 0.95), rgba(12, 16, 28, 0.95));
            border: 1px solid rgba(148, 163, 184, 0.18);
            border-radius: 14px;
            padding: 1rem;
            box-shadow: 0 14px 28px rgba(2, 6, 23, 0.28);
        }

        .tv-health-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            margin-bottom: 0.9rem;
        }

        .tv-health-title {
            margin: 0;
            font-size: 0.95rem;
            line-height: 1.2rem;
            font-weight: 700;
            color: #f8fafc;
        }

        .tv-health-subtitle {
            margin: 0.15rem 0 0;
            color: #aebacf;
            font-size: 0.74rem;
            line-height: 1rem;
        }

        .tv-metrics-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 0.8rem;
            margin-bottom: 0.9rem;
        }

        .tv-metric-card {
            border-radius: 12px;
            border: 1px solid transparent;
            padding: 0.8rem;
            min-height: 110px;
            position: relative;
            overflow: hidden;
        }

        .tv-metric-card::before {
            content: '';
            position: absolute;
            inset: 0;
            pointer-events: none;
            border-radius: inherit;
            background: linear-gradient(175deg, rgba(255, 255, 255, 0.06), transparent 45%);
        }

        .tv-metric-danger {
            border-color: rgba(239, 68, 68, 0.35);
            background: linear-gradient(135deg, rgba(127, 29, 29, 0.45), rgba(69, 10, 10, 0.45));
        }

        .tv-metric-success {
            border-color: rgba(34, 197, 94, 0.35);
            background: linear-gradient(135deg, rgba(20, 83, 45, 0.45), rgba(5, 46, 22, 0.45));
        }

        .tv-metric-warning {
            border-color: rgba(245, 158, 11, 0.35);
            background: linear-gradient(135deg, rgba(120, 53, 15, 0.45), rgba(69, 26, 3, 0.45));
        }

        .tv-metric-label {
            margin: 0;
            font-size: 0.68rem;
            line-height: 0.95rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-weight: 600;
            color: #dbe5f1;
        }

        .tv-metric-value {
            margin: 0.35rem 0 0;
            font-size: 1.8rem;
            line-height: 2rem;
            font-weight: 800;
            color: #f8fafc;
        }

        .tv-metric-hint {
            margin: 0.4rem 0 0;
            font-size: 0.74rem;
            line-height: 1rem;
            color: #cdd8e7;
        }

        .tv-failure-panel {
            border: 1px solid rgba(239, 68, 68, 0.22);
            border-radius: 12px;
            background: rgba(9, 12, 20, 0.78);
            overflow: hidden;
        }

        .tv-failure-panel-header {
            padding: 0.6rem 0.85rem;
            background: linear-gradient(145deg, rgba(127, 29, 29, 0.4), rgba(69, 10, 10, 0.25));
            border-bottom: 1px solid rgba(239, 68, 68, 0.2);
            font-size: 0.7rem;
            letter-spacing: 0.04em;
            font-weight: 700;
            text-transform: uppercase;
            color: #fecaca;
        }

        .tv-failure-scroll {
            max-height: 245px;
            overflow: auto;
        }

        .tv-failure-table {
            width: 100%;
            border-collapse: collapse;
        }

        .tv-failure-table thead th {
            position: sticky;
            top: 0;
            z-index: 1;
            text-align: left;
            font-size: 0.68rem;
            color: #b8c5d9;
            background: rgba(15, 23, 42, 0.95);
            padding: 0.5rem 0.7rem;
            border-bottom: 1px solid rgba(148, 163, 184, 0.2);
        }

        .tv-failure-table tbody td {
            font-size: 0.72rem;
            color: #dbe4f3;
            padding: 0.42rem 0.7rem;
            border-bottom: 1px solid rgba(148, 163, 184, 0.1);
            vertical-align: top;
        }

        .tv-failure-table tbody tr:nth-child(odd) {
            background: rgba(15, 23, 42, 0.38);
        }

        .tv-failure-code {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            color: #fecaca;
        }

        .tv-time-cell {
            white-space: nowrap;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
        }

        @media (max-width: 1024px) {
            .tv-metrics-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 720px) {
            .tv-metrics-grid {
                grid-template-columns: 1fr;
            }

            .tv-health-header {
                align-items: flex-start;
                flex-direction: column;
            }

            .tv-failure-scroll {
                max-height: 200px;
            }
        }
    </style>

    <x-filament::section
        heading="Ingestion Health"
        {{-- description="Quick debugging view for post-persist publish behaviour." --}}
        :icon="\Filament\Support\Icons\Heroicon::OutlinedExclamationTriangle"
        :collapsible="true"
        class="mb-6"
    >
        <div class="tv-health-shell">
            <div class="tv-health-header">
                <div>
                    <p class="tv-health-title">Pipeline Publish Diagnostics</p>
                    <p class="tv-health-subtitle">Tracks post-persist NATS publish reliability for telemetry.</p>
                </div>

                <x-filament::badge :color="$healthBadge['color']" size="sm">
                    {{ $healthBadge['label'] }}
                </x-filament::badge>
            </div>

            <div class="tv-metrics-grid">
                <div class="tv-metric-card tv-metric-danger">
                    <p class="tv-metric-label">Publish Failures (1h)</p>
                    <p class="tv-metric-value">{{ number_format($publishFailureCount) }}</p>
                    <p class="tv-metric-hint">Persisted, but hot-state or analytics publish failed.</p>
                </div>

                <div class="tv-metric-card tv-metric-success">
                    <p class="tv-metric-label">Processed (1h)</p>
                    <p class="tv-metric-value">{{ number_format($processedCount) }}</p>
                    <p class="tv-metric-hint">Telemetry persisted and publish stage completed.</p>
                </div>

                <div class="tv-metric-card tv-metric-warning">
                    <p class="tv-metric-label">Failure Rate (1h)</p>
                    <p class="tv-metric-value">{{ number_format($failureRate, 1) }}%</p>
                    <p class="tv-metric-hint">{{ number_format($recentTotal) }} completed publish attempts in the window.</p>
                </div>
            </div>

            @if ($recentPublishFailures->isNotEmpty())
                <div class="tv-failure-panel">
                    <div class="tv-failure-panel-header">Latest Failure Reasons</div>

                    <div class="tv-failure-scroll">
                        <table class="tv-failure-table">
                            <thead>
                                <tr>
                                    <th>Time</th>
                                    <th>Device</th>
                                    <th>Reason</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($recentPublishFailures as $failure)
                                    @php
                                        $deviceLabel = $failure->device?->name ?? $failure->device?->external_id ?? 'Unknown Device';
                                        $reason = data_get($failure->ingestionMessage?->error_summary, 'errors.hot_state')
                                            ?? data_get($failure->ingestionMessage?->error_summary, 'errors.analytics_publish')
                                            ?? 'Unknown publish error';
                                    @endphp
                                    <tr>
                                        <td class="tv-time-cell">{{ $failure->recorded_at?->format('H:i:s') }}</td>
                                        <td>{{ $deviceLabel }}</td>
                                        <td class="tv-failure-code" title="{{ $reason }}">{{ \Illuminate\Support\Str::limit($reason, 130) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>
</x-filament::section>

    @php
        $rawTelemetryDiagnosticsEnabled = \Laravel\Pennant\Feature::active('iot.diagnostics.raw_telemetry_stream');
    @endphp

    @if ($rawTelemetryDiagnosticsEnabled)
        <livewire:admin.telemetry-live-stream />

        @push('scripts')
            <script>
                document.addEventListener('livewire:init', () => {
                    if (window.__telemetryPusherBound || ! window.Pusher) {
                        return;
                    }

                    window.__telemetryPusherBound = true;

                    const pusher = new window.Pusher(@js(config('broadcasting.connections.reverb.key')), {
                        cluster: 'mt1',
                        wsHost: @js(config('broadcasting.connections.reverb.options.host')),
                        wsPort: @js(config('broadcasting.connections.reverb.options.port')),
                        wssPort: @js(config('broadcasting.connections.reverb.options.port')),
                        forceTLS: @js(config('broadcasting.connections.reverb.options.scheme') === 'https'),
                        enabledTransports: ['ws', 'wss'],
                        disableStats: true,
                    });

                    const channel = pusher.subscribe('telemetry');

                    channel.bind('telemetry.incoming', (event) => {
                        const params = new URLSearchParams(window.location.search);
                        const selectedDevice = params.get('device');
                        const selectedTopicSuffix = params.get('topic');

                        if (!selectedDevice || !selectedTopicSuffix) {
                            return;
                        }

                        const deviceMatches = selectedDevice === event?.device_external_id
                            || selectedDevice === event?.device_uuid;

                        const topicMatches = typeof event?.topic === 'string'
                            && event.topic.endsWith('/' + selectedTopicSuffix);

                        if (!deviceMatches || !topicMatches) {
                            return;
                        }

                        Livewire.dispatch('telemetryIncoming', { entry: event });
                    });
                });
            </script>
        @endpush
    @else
        <x-filament::section
            heading="Pre-Ingestion Stream"
            description="Diagnostic-only raw telemetry stream"
            :icon="\Filament\Support\Icons\Heroicon::OutlinedSignal"
        >
            <div class="space-y-3">
                <x-filament::badge color="warning" size="sm">
                    Diagnostics Disabled
                </x-filament::badge>

                <p class="text-sm text-gray-600 dark:text-gray-300">
                    Raw telemetry broadcasting is disabled by default so ingest does not fan out every inbound payload.
                    Enable <code>IOT_BROADCAST_RAW_TELEMETRY=true</code> only during targeted debugging.
                </p>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
