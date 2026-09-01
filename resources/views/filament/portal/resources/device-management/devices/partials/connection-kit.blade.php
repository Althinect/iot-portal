@php($connectionKit = $this->getConnectionKit())
@php($mqtt = $connectionKit['mqtt'])

<div class="space-y-6">
    <x-filament::section
        heading="Device connection kit"
        description="Profile-owned settings resolved for this device. Shared profile passwords are never exposed here."
        :icon="\Filament\Support\Icons\Heroicon::OutlinedSignal"
    >
        @if ($mqtt !== null)
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div>
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Broker</div>
                    <div class="mt-1 font-mono text-sm">{{ $mqtt['broker_host'] }}:{{ $mqtt['broker_port'] }}</div>
                </div>
                <div>
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Client ID</div>
                    <div class="mt-1 font-mono text-sm">{{ $connectionKit['device']['client_id'] }}</div>
                </div>
                <div>
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Transport security</div>
                    <div class="mt-1 text-sm">{{ $mqtt['use_tls'] ? 'TLS enabled' : 'TLS disabled' }} · {{ $mqtt['security_mode'] }}</div>
                </div>
                <div>
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Physical connection</div>
                    <div class="mt-1 text-sm">{{ \Illuminate\Support\Str::headline($this->deviceConnectionState ?? 'unknown') }}</div>
                </div>
            </div>

            <div class="mt-6 overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
                <table class="w-full divide-y divide-gray-200 text-left text-sm dark:divide-white/10">
                    <thead class="bg-gray-50 dark:bg-white/5">
                        <tr>
                            <th class="px-4 py-3 font-medium">Channel</th>
                            <th class="px-4 py-3 font-medium">Direction / Purpose</th>
                            <th class="px-4 py-3 font-medium">Resolved MQTT topic</th>
                            <th class="px-4 py-3 font-medium">QoS / Retain</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @forelse ($mqtt['channels'] as $channel)
                            <tr>
                                <td class="px-4 py-3 font-medium">{{ $channel['label'] }}</td>
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-300">
                                    {{ \Illuminate\Support\Str::headline($channel['direction']) }} · {{ \Illuminate\Support\Str::headline($channel['purpose']) }}
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex min-w-80 items-center gap-2" x-data="{ copied: false }">
                                        <code class="break-all text-xs">{{ $channel['address'] }}</code>
                                        <button
                                            type="button"
                                            class="shrink-0 rounded-lg px-2 py-1 text-xs font-medium text-primary-600 hover:bg-primary-50 dark:hover:bg-white/5"
                                            x-on:click="navigator.clipboard.writeText(@js($channel['address'])); copied = true; setTimeout(() => copied = false, 1500)"
                                            x-text="copied ? 'Copied' : 'Copy'"
                                        >Copy</button>
                                    </div>
                                </td>
                                <td class="px-4 py-3">{{ $channel['qos'] }} / {{ $channel['retain'] ? 'Yes' : 'No' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-6 text-center text-gray-500">No MQTT channels are configured on this profile version.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-sm text-gray-600 dark:text-gray-300">
                This profile does not use MQTT. Use the profile's protocol configuration for device communication.
            </p>
        @endif
    </x-filament::section>

    <x-filament::section
        heading="Portal simulation"
        description="Simulated telemetry exercises the internal ingestion path. It does not validate a physical MQTT connection; use the physical connection state and received telemetry below for that confirmation."
        :icon="\Filament\Support\Icons\Heroicon::OutlinedBeaker"
        compact
    />
</div>
