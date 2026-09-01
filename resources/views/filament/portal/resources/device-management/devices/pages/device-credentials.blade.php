<x-filament-panels::page>
    <x-filament::section
        heading="Credential lifecycle"
        description="Issue or rotate device credentials without exposing platform transport configuration."
        :icon="\Filament\Support\Icons\Heroicon::OutlinedKey"
    >
        <div class="space-y-4">
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <div class="text-sm text-gray-500">Device</div>
                    <div class="font-medium">{{ $this->getRecord()->name }}</div>
                </div>
                <div>
                    <div class="text-sm text-gray-500">Certificate status</div>
                    <div class="font-medium">{{ $this->hasActiveCertificate() ? 'Active' : 'Not issued' }}</div>
                </div>
            </div>

            @if ($this->usesMqttX509())
                <div class="flex flex-wrap gap-3">
                    <x-filament::button wire:click="issue" icon="heroicon-o-key">
                        {{ $this->hasActiveCertificate() ? 'Rotate credentials' : 'Issue credentials' }}
                    </x-filament::button>

                    @if ($this->hasActiveCertificate())
                        <x-filament::button wire:click="revoke" color="danger" icon="heroicon-o-shield-exclamation">
                            Revoke credentials
                        </x-filament::button>
                    @endif
                </div>
            @else
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    This device profile is not configured for MQTT X.509 mTLS. Shared profile passwords are not exposed in the Portal.
                </p>
            @endif
        </div>
    </x-filament::section>

    @if ($credentialBundle !== null)
        <x-filament::section
            heading="One-time credential download"
            description="Download this file now. It includes the broker, client ID, resolved topics, CA certificate, device certificate, and private key. After download or page refresh, the private key will no longer be available in the Portal."
            :icon="\Filament\Support\Icons\Heroicon::OutlinedArrowDownTray"
            class="mt-6"
        >
            <x-filament::button wire:click="downloadCredentials" color="warning" icon="heroicon-o-arrow-down-tray">
                Download credentials JSON
            </x-filament::button>
        </x-filament::section>
    @endif
</x-filament-panels::page>
