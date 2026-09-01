<x-filament-panels::page>
    @if (method_exists($this, 'getConnectionKit'))
        @include('filament.portal.resources.device-management.devices.partials.connection-kit')
    @endif

    <style>
        .dc-status-indicator {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.375rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.8125rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .dc-status-indicator[data-state="online"] {
            background: rgba(16, 185, 129, 0.1);
            color: rgb(5, 150, 105);
        }
        .dc-status-indicator[data-state="offline"] {
            background: rgba(239, 68, 68, 0.1);
            color: rgb(220, 38, 38);
        }
        .dc-status-indicator[data-state="unknown"] {
            background: rgba(107, 114, 128, 0.1);
            color: rgb(107, 114, 128);
        }
        .dark .dc-status-indicator[data-state="online"] {
            background: rgba(52, 211, 153, 0.15);
            color: rgb(167, 243, 208);
        }
        .dark .dc-status-indicator[data-state="offline"] {
            background: rgba(248, 113, 113, 0.15);
            color: rgb(252, 165, 165);
        }
        .dark .dc-status-indicator[data-state="unknown"] {
            background: rgba(156, 163, 175, 0.12);
            color: rgb(209, 213, 219);
        }
        .dc-status-dot {
            width: 0.5rem;
            height: 0.5rem;
            border-radius: 9999px;
            flex-shrink: 0;
        }
        .dc-status-dot[data-state="online"] {
            background: rgb(16, 185, 129);
            animation: dc-pulse 2s infinite;
        }
        .dc-status-dot[data-state="offline"] {
            background: rgb(239, 68, 68);
        }
        .dc-status-dot[data-state="unknown"] {
            background: rgb(156, 163, 175);
        }
        @keyframes dc-pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        .dc-control-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 0.75rem;
            margin-top: 1rem;
        }
        .dc-control-card {
            border: 1px solid rgba(0, 0, 0, 0.08);
            border-radius: 0.5rem;
            padding: 0.75rem;
            background: rgba(0, 0, 0, 0.02);
        }
        .dark .dc-control-card {
            border-color: rgba(255, 255, 255, 0.08);
            background: rgba(255, 255, 255, 0.02);
        }
        .dc-control-label {
            display: block;
            font-size: 0.8125rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: rgb(55, 65, 81);
        }
        .dark .dc-control-label {
            color: rgb(229, 231, 235);
        }
        .dc-control-unit {
            color: rgb(107, 114, 128);
            font-weight: 500;
            font-size: 0.75rem;
            margin-left: 0.25rem;
        }
        .dc-control-meta {
            margin-top: 0.375rem;
            font-size: 0.6875rem;
            color: rgb(107, 114, 128);
            display: flex;
            justify-content: space-between;
            gap: 0.5rem;
        }
        .dc-control-input,
        .dc-control-select,
        .dc-control-textarea {
            width: 100%;
            border: 1px solid rgba(0, 0, 0, 0.15);
            border-radius: 0.375rem;
            padding: 0.375rem 0.5rem;
            font-size: 0.8125rem;
            background: white;
            color: rgb(31, 41, 55);
        }
        .dark .dc-control-input,
        .dark .dc-control-select,
        .dark .dc-control-textarea {
            border-color: rgba(255, 255, 255, 0.2);
            background: rgba(255, 255, 255, 0.05);
            color: rgb(243, 244, 246);
        }
        .dc-control-slider {
            width: 100%;
            accent-color: rgb(245, 158, 11);
        }
        .dc-control-toggle {
            width: 1rem;
            height: 1rem;
        }
        .dc-log {
            border: 1px solid rgba(0, 0, 0, 0.1);
            border-radius: 0.5rem;
            overflow: hidden;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-size: 0.8125rem;
            line-height: 1.6;
        }
        .dark .dc-log {
            border-color: rgba(255, 255, 255, 0.1);
        }
        .dc-log-header {
            display: grid;
            grid-template-columns: 6rem 10rem 1fr;
            padding: 0.5rem 0.75rem;
            background: rgba(0, 0, 0, 0.03);
            border-bottom: 1px solid rgba(0, 0, 0, 0.08);
            font-size: 0.6875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: rgb(107, 114, 128);
        }
        .dark .dc-log-header {
            background: rgba(255, 255, 255, 0.03);
            border-bottom-color: rgba(255, 255, 255, 0.08);
            color: rgb(156, 163, 175);
        }
        .dc-log-body {
            max-height: 20rem;
            overflow-y: auto;
        }
        .dc-log-row {
            display: grid;
            grid-template-columns: 6rem 10rem 1fr;
            padding: 0.375rem 0.75rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.04);
            align-items: start;
        }
        .dark .dc-log-row {
            border-bottom-color: rgba(255, 255, 255, 0.04);
        }
        .dc-log-row:last-child {
            border-bottom: none;
        }
        .dc-log-time {
            color: rgb(156, 163, 175);
            font-variant-numeric: tabular-nums;
        }
        .dc-log-detail {
            color: rgb(75, 85, 99);
            white-space: pre-wrap;
            word-break: break-all;
            overflow-wrap: break-word;
        }
        .dark .dc-log-detail {
            color: rgb(209, 213, 219);
        }
        .dc-badge {
            display: inline-block;
            padding: 0.0625rem 0.375rem;
            border-radius: 0.25rem;
            font-size: 0.6875rem;
            font-weight: 600;
            line-height: 1.5;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        }
        .dc-badge[data-type="command.dispatched"] { background: rgba(59, 130, 246, 0.1); color: rgb(37, 99, 235); }
        .dc-badge[data-type="command.sent"] { background: rgba(6, 182, 212, 0.1); color: rgb(8, 145, 178); }
        .dc-badge[data-type="command.completed"] { background: rgba(16, 185, 129, 0.1); color: rgb(5, 150, 105); }
        .dc-badge[data-type="command.timeout"] { background: rgba(245, 158, 11, 0.12); color: rgb(180, 83, 9); }
        .dc-badge[data-type="device.state.received"] { background: rgba(16, 185, 129, 0.1); color: rgb(5, 150, 105); }
        .dc-badge[data-type="info"] { background: rgba(107, 114, 128, 0.1); color: rgb(75, 85, 99); }
        .dc-badge[data-type="error"] { background: rgba(239, 68, 68, 0.1); color: rgb(220, 38, 38); }
        .dark .dc-badge[data-type="command.dispatched"] { background: rgba(96, 165, 250, 0.15); color: rgb(147, 197, 253); }
        .dark .dc-badge[data-type="command.sent"] { background: rgba(34, 211, 238, 0.15); color: rgb(165, 243, 252); }
        .dark .dc-badge[data-type="command.completed"] { background: rgba(52, 211, 153, 0.15); color: rgb(167, 243, 208); }
        .dark .dc-badge[data-type="command.timeout"] { background: rgba(253, 186, 116, 0.15); color: rgb(251, 191, 36); }
        .dark .dc-badge[data-type="device.state.received"] { background: rgba(52, 211, 153, 0.15); color: rgb(167, 243, 208); }
        .dark .dc-badge[data-type="info"] { background: rgba(156, 163, 175, 0.12); color: rgb(209, 213, 219); }
        .dark .dc-badge[data-type="error"] { background: rgba(248, 113, 113, 0.15); color: rgb(252, 165, 165); }
        .dc-separator {
            padding: 0.25rem 0.75rem;
            text-align: center;
            font-size: 0.6875rem;
            color: rgb(156, 163, 175);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            border-bottom: 1px solid rgba(0, 0, 0, 0.04);
        }
        .dark .dc-separator {
            border-bottom-color: rgba(255, 255, 255, 0.04);
            color: rgb(107, 114, 128);
        }
        .dc-empty {
            padding: 2rem 0.75rem;
            text-align: center;
            color: rgb(156, 163, 175);
            font-size: 0.8125rem;
        }
        .dc-state-grid {
            display: grid;
            grid-template-columns: 3fr 1.5fr;
            gap: 1rem;
            align-items: start;
        }
        @media (max-width: 768px) {
            .dc-state-grid {
                grid-template-columns: 1fr;
            }
        }
        .dc-state-list {
            display: grid;
            gap: 0.75rem;
        }
        .dc-state-card {
            border: 1px solid rgba(0, 0, 0, 0.08);
            border-radius: 0.5rem;
            overflow: hidden;
        }
        .dark .dc-state-card {
            border-color: rgba(255, 255, 255, 0.08);
        }
        .dc-state-header {
            padding: 0.5rem 0.75rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.06);
            font-size: 0.75rem;
            color: rgb(75, 85, 99);
            background: rgba(0, 0, 0, 0.02);
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            word-break: break-all;
        }
        .dark .dc-state-header {
            border-bottom-color: rgba(255, 255, 255, 0.06);
            background: rgba(255, 255, 255, 0.03);
            color: rgb(209, 213, 219);
        }
        .dc-state-json {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-size: 0.75rem;
            line-height: 1.5;
            white-space: pre-wrap;
            word-break: break-all;
            overflow-wrap: break-word;
            padding: 0.75rem;
            max-height: 14rem;
            overflow-y: auto;
        }
        .dc-state-meta {
            font-size: 0.6875rem;
            color: rgb(156, 163, 175);
            padding: 0 0.75rem 0.75rem;
        }
        .dc-state-empty {
            padding: 2rem 0.75rem;
            text-align: center;
            color: rgb(156, 163, 175);
            font-size: 0.8125rem;
            font-style: italic;
        }
    </style>

    <x-filament::section>
        <x-slot name="heading">Send Command</x-slot>

        <x-slot name="afterHeader">
            <div
                x-data="{ connectionState: @js($this->deviceConnectionState ?? 'unknown') }"
                x-on:device-connection-changed.window="connectionState = $event.detail.state"
            >
                <div class="dc-status-indicator" :data-state="connectionState">
                    <div class="dc-status-dot" :data-state="connectionState"></div>
                    <span x-text="connectionState === 'online' ? 'Device Online' : (connectionState === 'offline' ? 'Device Offline' : 'Status Unknown')"></span>
                </div>
            </div>
        </x-slot>

        <x-slot name="description">Select a command channel and control parameters. Enable advanced mode to send raw JSON.</x-slot>

        @if(count($this->subscribeChannelOptions) > 0)
            {{ $this->form }}
        @else
            <p style="color: rgb(107, 114, 128); font-size: 0.875rem;">
                No command channels configured for this device profile version.
            </p>
        @endif
    </x-filament::section>

    <div class="dc-state-grid"
         x-data="deviceControlStream()"
         x-init="init()"
         x-on:clear-device-log.window="events = []"
    >
        <x-filament::section>
            <x-slot name="heading">
                <div style="display: flex; align-items: center; justify-content: space-between; width: 100%;">
                    <span>Message Flow</span>
                    <x-filament::button
                        size="xs"
                        color="gray"
                        icon="heroicon-m-trash"
                        x-on:click="$dispatch('clear-device-log')"
                    >
                        Clear
                    </x-filament::button>
                </div>
            </x-slot>
            <x-slot name="description">Real-time command/state lifecycle from Reverb.</x-slot>

            <div class="dc-log">
                <div class="dc-log-header">
                    <span>Time</span>
                    <span>Event</span>
                    <span>Details</span>
                </div>
                <div class="dc-log-body" x-ref="eventLog">
                    <template x-if="events.length === 0">
                        <div class="dc-empty">Waiting for events... Send a command or start the mock device.</div>
                    </template>
                    <template x-for="(event, index) in events" :key="index">
                        <div>
                            <template x-if="event.type === 'cycle-separator'">
                                <div class="dc-separator">cycle complete</div>
                            </template>
                            <template x-if="event.type !== 'cycle-separator'">
                                <div class="dc-log-row">
                                    <span class="dc-log-time" x-text="event.time"></span>
                                    <span><span class="dc-badge" :data-type="event.type" x-text="event.label"></span></span>
                                    <span class="dc-log-detail" x-text="event.detail"></span>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Device States (Per Channel)</x-slot>
            <x-slot name="description">Last known state payloads keyed by publish channel.</x-slot>

            <template x-if="Object.keys(topicStates).length === 0">
                <div class="dc-state-empty">No state received yet.</div>
            </template>

            <template x-if="Object.keys(topicStates).length > 0">
                <div class="dc-state-list">
                    <template x-for="(state, topic) in topicStates" :key="topic">
                        <div class="dc-state-card">
                            <div class="dc-state-header" x-text="topic"></div>
                            <div class="dc-state-json" x-text="JSON.stringify(state.payload, null, 2)"></div>
                            <div class="dc-state-meta">
                                Updated <span x-text="formatTime(state.stored_at)"></span>
                            </div>
                        </div>
                    </template>
                </div>
            </template>
        </x-filament::section>
    </div>

    {{ $this->table }}

    @push('scripts')
        <script>
            function deviceControlStream() {
                const initialStates = @js($this->initialDeviceStates);

                const topicStates = {};

                if (Array.isArray(initialStates)) {
                    initialStates.forEach((state) => {
                        if (state?.topic) {
                            topicStates[state.topic] = {
                                payload: state.payload ?? {},
                                stored_at: state.stored_at ?? new Date().toISOString(),
                            };
                        }
                    });
                }

                return {
                    events: [],
                    topicStates,

                    init() {
                        const deviceUuid = @js($this->deviceUuid);

                        this.handleIncoming = (payload) => {
                            if (payload?.deviceUuid !== deviceUuid) {
                                return;
                            }

                            switch (payload?.type) {
                                case 'command.dispatched':
                                    this.addEvent('command.dispatched', 'Dispatched',
                                        `Log #${payload.data.command_log_id} | Topic: ${payload.data.topic} | Payload: ${JSON.stringify(payload.data.command_payload)}`
                                    );
                                    break;
                                case 'command.sent':
                                    this.addEvent('command.sent', 'Sent',
                                        `Log #${payload.data.command_log_id} | NATS: ${payload.data.nats_subject} | ${payload.data.sent_at}`
                                    );
                                    break;
                                case 'command.completed':
                                    this.addEvent('command.completed', 'Completed',
                                        `Log #${payload.data.command_log_id} | Topic: ${payload.data.topic} | Completed: ${payload.data.completed_at}`
                                    );
                                    this.addSeparator();
                                    break;
                                case 'command.timeout':
                                    this.addEvent('command.timeout', 'Timeout',
                                        `Log #${payload.data.command_log_id} | ${payload.data.error_message ?? 'Timed out'}`
                                    );
                                    break;
                                case 'device.state.received':
                                    this.addEvent('device.state.received', 'Received',
                                        `Topic: ${payload.data.topic} | Payload: ${JSON.stringify(payload.data.payload)} | ${payload.data.received_at}`
                                    );

                                    this.topicStates[payload.data.topic] = {
                                        payload: payload.data.payload ?? {},
                                        stored_at: payload.data.received_at ?? new Date().toISOString(),
                                    };

                                    if (payload.data.payload) {
                                        this.$wire.call('updateControlValuesFromState', payload.data.payload);
                                    }

                                    break;
                                case 'info':
                                    this.addEvent('info', 'Info', payload.data?.message ?? 'WebSocket connected.');
                                    break;
                                case 'error':
                                    this.addEvent('error', 'Error', payload.data?.message ?? 'WebSocket error.');
                                    break;
                            }
                        };

                        if (this._boundHandler) {
                            window.removeEventListener('device-control-event', this._boundHandler);
                        }
                        this._boundHandler = (e) => this.handleIncoming(e.detail);
                        window.addEventListener('device-control-event', this._boundHandler);

                        if (window.__deviceControlPusherBound && window.__deviceControlDeviceUuid === deviceUuid) {
                            return;
                        }

                        window.__deviceControlDeviceUuid = deviceUuid;
                        window.__deviceControlPusherBound = true;

                        const echoPusher = window.Echo?.connector?.pusher ?? null;

                        if (echoPusher) {
                            window.__deviceControlPusher = echoPusher;
                        } else if (!window.__deviceControlPusher && window.Pusher) {
                            const configuredHost = @js(config('filament.broadcasting.echo.wsHost'));
                            const configuredPort = Number(@js(config('filament.broadcasting.echo.wsPort')));
                            const configuredForceTls = @js((bool) config('filament.broadcasting.echo.forceTLS'));
                            const originScheme = window.location.protocol.replace(':', '');
                            const runtimeHost = window.location.hostname;
                            const isLocalRuntimeHost = runtimeHost === 'localhost' || runtimeHost === '127.0.0.1' || runtimeHost.endsWith('.test');
                            const wsScheme = configuredForceTls ? 'https' : (isLocalRuntimeHost ? originScheme : originScheme || 'http');
                            const wsHost = isLocalRuntimeHost || !configuredHost || configuredHost === '127.0.0.1'
                                ? runtimeHost
                                : configuredHost;
                            const wsPort = Number.isFinite(configuredPort) && configuredPort > 0
                                ? configuredPort
                                : (window.location.port ? Number(window.location.port) : (wsScheme === 'https' ? 443 : 80));

                            window.__deviceControlPusher = new window.Pusher(@js(config('broadcasting.connections.reverb.key')), {
                                cluster: 'mt1',
                                wsHost,
                                wsPort,
                                wssPort: wsPort,
                                forceTLS: wsScheme === 'https',
                                enabledTransports: ['ws', 'wss'],
                                disableStats: true,
                            });
                        }

                        if (!window.__deviceControlPusher) {
                            this.addEvent('error', 'Error', 'WebSocket client not available.');
                            return;
                        }

                        window.__deviceControlPusher.connection.bind('connected', () => {
                            window.dispatchEvent(new CustomEvent('device-control-event', {
                                detail: { type: 'info', deviceUuid, data: { message: 'WebSocket connected.' } },
                            }));
                        });

                        window.__deviceControlPusher.connection.bind('error', (error) => {
                            const errorMessage = error?.error?.data?.message
                                ?? error?.error?.message
                                ?? 'WebSocket connection error.';

                            window.dispatchEvent(new CustomEvent('device-control-event', {
                                detail: { type: 'error', deviceUuid, data: { message: errorMessage } },
                            }));
                        });

                        window.__deviceControlPusher.connection.bind('unavailable', () => {
                            window.dispatchEvent(new CustomEvent('device-control-event', {
                                detail: { type: 'error', deviceUuid, data: { message: 'WebSocket unavailable.' } },
                            }));
                        });

                        if (window.__deviceControlChannel) {
                            window.__deviceControlPusher.unsubscribe(window.__deviceControlChannel.name);
                        }

                        window.__deviceControlChannel = window.__deviceControlPusher.subscribe('device-control.' + deviceUuid);

                        ['command.dispatched', 'command.sent', 'command.completed', 'command.timeout', 'device.state.received'].forEach((evt) => {
                            window.__deviceControlChannel.bind(evt, (data) => {
                                window.dispatchEvent(new CustomEvent('device-control-event', {
                                    detail: { type: evt, deviceUuid, data },
                                }));
                            });
                        });

                        window.__deviceControlChannel.bind('device.connection.changed', (data) => {
                            window.dispatchEvent(new CustomEvent('device-connection-changed', {
                                detail: { state: data.connection_state, deviceUuid: data.device_uuid },
                            }));

                            this.$wire.call('updateDeviceConnectionState', data.connection_state);

                            const label = data.connection_state === 'online' ? 'Online' : 'Offline';
                            this.addEvent(
                                data.connection_state === 'online' ? 'info' : 'error',
                                label,
                                `Device is now ${data.connection_state} (${data.last_seen_at ?? '—'})`
                            );
                        });
                    },

                    addEvent(type, label, detail) {
                        this.events.push({ type, label, detail, time: new Date().toLocaleTimeString() });
                        this.$nextTick(() => {
                            if (this.$refs.eventLog) {
                                this.$refs.eventLog.scrollTop = this.$refs.eventLog.scrollHeight;
                            }
                        });
                    },

                    addSeparator() {
                        this.events.push({ type: 'cycle-separator', time: '' });
                        this.$nextTick(() => {
                            if (this.$refs.eventLog) {
                                this.$refs.eventLog.scrollTop = this.$refs.eventLog.scrollHeight;
                            }
                        });
                    },

                    formatTime(isoString) {
                        if (!isoString) {
                            return '—';
                        }

                        try {
                            return new Date(isoString).toLocaleTimeString();
                        } catch (e) {
                            return isoString;
                        }
                    },
                };
            }
        </script>
    @endpush
</x-filament-panels::page>
