<x-filament-panels::page>
    <x-filament::section
        heading="Operational Runtime Controls"
        description="Edit global defaults and tenant-specific overrides without touching environment files."
        :icon="\Filament\Support\Icons\Heroicon::OutlinedAdjustmentsHorizontal"
        class="mb-6"
    >
        {{ $this->controlsForm }}
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
