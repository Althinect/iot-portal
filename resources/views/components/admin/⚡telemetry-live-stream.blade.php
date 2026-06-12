<?php

use App\Support\DeviceSelectOptions;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceSchema\Enums\TopicDirection;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component implements HasForms {
    use InteractsWithForms;

    /**
     * @var array<string, string>
     */
    protected $listeners = [
        'telemetryIncoming' => 'handleTelemetryIncoming',
    ];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $liveTelemetry = [];

    public int $messageCount = 0;

    /**
     * @var array<string, string>
     */
    public array $topicOptions = [];

    #[Url(as: 'device')]
    public ?string $selectedDevice = null;

    #[Url(as: 'topic')]
    public ?string $selectedTopicSuffix = null;

    public int $payloadPreviewLimit = 160;

    public function mount(): void
    {
        $this->loadTopicOptionsForDevice($this->selectedDevice);

        if ($this->selectedTopicSuffix && !array_key_exists($this->selectedTopicSuffix, $this->topicOptions)) {
            $this->selectedTopicSuffix = null;
        }

        if (!$this->selectedTopicSuffix && !empty($this->topicOptions)) {
            $this->selectedTopicSuffix = (string) array_key_first($this->topicOptions);
        }
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('selectedDevice')
                    ->label('Device')
                    ->placeholder('Select a device…')
                    ->options(function (): array {
                        return DeviceSelectOptions::groupedByType(Device::query()->orderBy('name')->limit(50), valueResolver: static fn(Device $device): string => (string) ($device->external_id ?: $device->uuid), useUuidFallback: true);
                    })
                    ->searchable()
                    ->getSearchResultsUsing(function (string $search): array {
                        $search = trim($search);

                        if ($search === '') {
                            return [];
                        }

                        return DeviceSelectOptions::groupedByType(DeviceSelectOptions::search(Device::query(), $search, useUuidFallback: true)->orderBy('name')->limit(50), valueResolver: static fn(Device $device): string => (string) ($device->external_id ?: $device->uuid), useUuidFallback: true);
                    })
                    ->getOptionLabelUsing(function (?string $value): ?string {
                        if (blank($value)) {
                            return null;
                        }

                        $value = (string) $value;

                        $deviceQuery = Device::query();

                        if (Str::isUuid($value)) {
                            $deviceQuery->where('uuid', $value);
                        } else {
                            $deviceQuery->where('external_id', $value);
                        }

                        $device = $deviceQuery->first(['name', 'external_id', 'uuid']);

                        if (!$device) {
                            return null;
                        }

                        return DeviceSelectOptions::label($device, useUuidFallback: true);
                    })
                    ->live(),

                Select::make('selectedTopicSuffix')->label('Topic')->placeholder('Select a topic…')->options(fn(): array => $this->topicOptions)->searchable()->disabled(fn(): bool => blank($this->selectedDevice))->live(),

                Select::make('payloadPreviewLimit')
                    ->label('Trim')
                    ->options([
                        80 => '80 chars',
                        120 => '120 chars',
                        160 => '160 chars',
                        240 => '240 chars',
                    ])
                    ->live(),
            ])
            ->columns(3);
    }

    public function updatedSelectedDevice(): void
    {
        $this->loadTopicOptionsForDevice($this->selectedDevice);

        if ($this->selectedTopicSuffix && !array_key_exists($this->selectedTopicSuffix, $this->topicOptions)) {
            $this->selectedTopicSuffix = null;
        }

        if (!$this->selectedTopicSuffix && !empty($this->topicOptions)) {
            $this->selectedTopicSuffix = (string) array_key_first($this->topicOptions);
        }

        $this->resetStream();
    }

    public function updatedSelectedTopicSuffix(): void
    {
        $this->resetStream();
    }

    public function resetStream(): void
    {
        $this->liveTelemetry = [];
        $this->messageCount = 0;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function eventMatchesSelectedDevice(array $entry): bool
    {
        if (!$this->selectedDevice) {
            return false;
        }

        $entryDeviceExternalId = $entry['device_external_id'] ?? null;
        $entryDeviceUuid = $entry['device_uuid'] ?? null;
        $selectedDevice = (string) $this->selectedDevice;

        return $selectedDevice === $entryDeviceExternalId || $selectedDevice === $entryDeviceUuid;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function eventMatchesSelectedTopic(array $entry): bool
    {
        if (!$this->selectedTopicSuffix) {
            return false;
        }

        $entryTopic = $entry['topic'] ?? null;

        if (!is_string($entryTopic) || trim($entryTopic) === '') {
            return false;
        }

        $selectedTopicSuffix = trim((string) $this->selectedTopicSuffix, '/.');
        $entryTopic = trim($entryTopic);

        return $entryTopic === $selectedTopicSuffix
            || str_ends_with($entryTopic, '/' . $selectedTopicSuffix)
            || str_ends_with($entryTopic, '.' . $selectedTopicSuffix);
    }

    private function loadTopicOptionsForDevice(?string $selectedDevice): void
    {
        $this->topicOptions = [];

        if (!$selectedDevice) {
            return;
        }

        $selectedDevice = (string) $selectedDevice;

        $deviceQuery = Device::query()->with(['schemaVersion.topics']);

        if (Str::isUuid($selectedDevice)) {
            $deviceQuery->where('uuid', $selectedDevice);
        } else {
            $deviceQuery->where('external_id', $selectedDevice);
        }

        $device = $deviceQuery->first();

        if (!$device?->schemaVersion) {
            return;
        }

        /** @var Collection<int, \App\Domain\DeviceSchema\Models\SchemaVersionTopic> $topics */
        $topics = $device->schemaVersion->topics->where('direction', TopicDirection::Publish)->sortBy('sequence');

        $this->topicOptions = $topics->mapWithKeys(fn($topic): array => [$topic->suffix => $topic->label])->all();
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    public function handleTelemetryIncoming(array $entry): void
    {
        if (!$this->selectedDevice || !$this->selectedTopicSuffix) {
            return;
        }

        if (!$this->eventMatchesSelectedDevice($entry) || !$this->eventMatchesSelectedTopic($entry)) {
            return;
        }

        $this->messageCount++;

        array_unshift($this->liveTelemetry, $entry);

        $this->liveTelemetry = array_slice($this->liveTelemetry, 0, 25);
    }
};
?>

<x-filament::section heading="Pre-Ingestion Stream" description="Live from Reverb — before database ingestion"
    :icon="\Filament\Support\Icons\Heroicon::OutlinedSignal">
    <x-slot:afterHeader>
        <div style="display:flex; gap: .5rem; align-items:center; flex-wrap:wrap;">
            <x-filament::badge color="gray" size="sm">
                {{ $messageCount }} received
            </x-filament::badge>

            <x-filament::badge color="primary" size="sm">
                {{ count($liveTelemetry) }}/25 shown
            </x-filament::badge>

            <x-filament::badge :color="count($liveTelemetry) > 0 ? 'success' : 'gray'" size="sm">
                {{ count($liveTelemetry) > 0 ? 'Live' : 'Idle' }}
            </x-filament::badge>
        </div>
    </x-slot:afterHeader>

    <div>
        {{ $this->form }}

        <div style="margin-top: .75rem;">
            <x-filament::icon-button color="gray" size="sm" :icon="\Filament\Support\Icons\Heroicon::OutlinedArrowPath" tooltip="Clear"
                wire:click="resetStream" />
        </div>
    </div>

    <div style="margin-top: 1.5rem;"></div>

    @if (!$selectedDevice || !$selectedTopicSuffix)
        <x-filament::empty-state heading="Select filters to start"
            description="Choose a device and topic to begin streaming." :icon="\Filament\Support\Icons\Heroicon::OutlinedSignal" icon-color="gray" />
    @elseif (count($liveTelemetry) === 0)
        <x-filament::empty-state heading="Waiting for telemetry…"
            description="Run the simulator to start streaming data for the selected device & topic." :icon="\Filament\Support\Icons\Heroicon::OutlinedSignal"
            icon-color="gray" />
    @else
        <div class="fi-ta-content-ctn fi-fixed-positioning-context">
            <table class="fi-ta-table">
                <thead>
                    <tr>
                        <th class="fi-ta-header-cell">#</th>
                        <th class="fi-ta-header-cell">Time</th>
                        <th class="fi-ta-header-cell">External ID</th>
                        <th class="fi-ta-header-cell">Topic</th>
                        <th class="fi-ta-header-cell">Payload</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($liveTelemetry as $index => $entry)
                        @php
                            $time = $entry['received_at'] ?? null;
                            $formattedTime = $time ? \Illuminate\Support\Carbon::parse($time)->format('H:i:s.v') : '—';
                            $payloadPretty =
                                json_encode($entry['payload'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?:
                                '{}';
                            $payloadInline = json_encode($entry['payload'] ?? [], JSON_UNESCAPED_SLASHES) ?: '{}';
                            $payloadPreview = \Illuminate\Support\Str::limit(
                                $payloadInline,
                                (int) $payloadPreviewLimit,
                                '…',
                            );
                        @endphp

                        <tr class="fi-ta-row fi-striped">
                            <td class="fi-ta-cell">
                                <code>{{ $messageCount - $index }}</code>
                            </td>
                            <td class="fi-ta-cell">
                                <code>{{ $formattedTime }}</code>
                            </td>
                            <td class="fi-ta-cell">
                                <x-filament::badge color="primary" size="sm">
                                    <code>{{ $entry['device_external_id'] ?? '—' }}</code>
                                </x-filament::badge>
                            </td>
                            <td class="fi-ta-cell">
                                <code title="{{ $entry['topic'] ?? '' }}"
                                    style="display:inline-block; max-width: 22rem; overflow:hidden; text-overflow: ellipsis; white-space: nowrap; vertical-align: bottom;">
                                    {{ $entry['topic'] ?? '—' }}
                                </code>
                            </td>
                            <td class="fi-ta-cell">
                                <div x-data="{
                                    copied: false,
                                    payload: {{ \Illuminate\Support\Js::from($payloadPretty) }},
                                    async copyPayload() {
                                        try {
                                            if (window?.navigator?.clipboard?.writeText) {
                                                await window.navigator.clipboard.writeText(this.payload);
                                            } else {
                                                const textarea = document.createElement('textarea');
                                                textarea.value = this.payload;
                                                textarea.style.position = 'fixed';
                                                textarea.style.left = '-9999px';
                                                textarea.style.top = '0';
                                                document.body.appendChild(textarea);
                                                textarea.focus();
                                                textarea.select();
                                                document.execCommand('copy');
                                                textarea.remove();
                                            }
                                
                                            this.copied = true;
                                            setTimeout(() => this.copied = false, 1500);
                                        } catch (e) {
                                            console.error('Failed to copy payload', e);
                                        }
                                    },
                                }" style="display:flex; gap: .5rem; align-items:center;">
                                    <code title="{{ $payloadPretty }}"
                                        style="display:inline-block; max-width: 36rem; overflow:hidden; text-overflow: ellipsis; white-space: nowrap;">
                                        {{ $payloadPreview }}
                                    </code>

                                    <x-filament::icon-button color="gray" size="sm" :icon="\Filament\Support\Icons\Heroicon::OutlinedClipboardDocument"
                                        tooltip="Copy payload" x-on:click="copyPayload()" x-show="!copied" />

                                    <x-filament::icon-button color="success" size="sm" :icon="\Filament\Support\Icons\Heroicon::OutlinedCheck"
                                        tooltip="Copied" x-cloak x-show="copied" />
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament::section>
