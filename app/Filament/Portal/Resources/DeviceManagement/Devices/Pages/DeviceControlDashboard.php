<?php

declare(strict_types=1);

namespace App\Filament\Portal\Resources\DeviceManagement\Devices\Pages;

use App\Domain\DeviceManagement\Jobs\SimulateDevicePublishingJob;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Services\DeviceConnectionKitBuilder;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Filament\Admin\Resources\DeviceManagement\Devices\Pages\DeviceControlDashboard as AdminDeviceControlDashboard;
use App\Filament\Portal\Resources\DeviceManagement\Devices\DeviceResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class DeviceControlDashboard extends AdminDeviceControlDashboard
{
    protected static string $resource = DeviceResource::class;

    /**
     * @var array{
     *     device: array{name: string, identifier: string, client_id: string},
     *     profile: array{name: string, version: int},
     *     mqtt: array{broker_host: string, broker_port: int, use_tls: bool, security_mode: string, x509_enabled: bool, channels: list<array{key: string, label: string, direction: string, purpose: string, address: string, qos: int, retain: bool}>}|null
     * }
     */
    public array $connectionKit = [];

    public function mount(int|string $record): void
    {
        parent::mount($record);
        Gate::authorize('control', $this->getRecord());
        $this->connectionKit = app(DeviceConnectionKitBuilder::class)->build($this->device());
    }

    public function sendCommand(): void
    {
        Gate::authorize('control', $this->getRecord());
        parent::sendCommand();
    }

    public function getHeaderActions(): array
    {
        return [
            Action::make('simulatePublishing')
                ->label('Simulate Telemetry')
                ->icon(Heroicon::OutlinedPlay)
                ->modalHeading('Simulate portal telemetry')
                ->modalDescription('Generate test telemetry through the internal ingestion path. This does not prove that a physical MQTT client is connected.')
                ->schema([
                    TextInput::make('count')
                        ->label('Iterations')
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(500)
                        ->default(10)
                        ->required(),
                    TextInput::make('interval')
                        ->label('Interval (seconds)')
                        ->numeric()
                        ->integer()
                        ->minValue(0)
                        ->maxValue(60)
                        ->default(1)
                        ->required(),
                    Radio::make('device_channel_id')
                        ->label('Publish channel')
                        ->options($this->getPublishChannelOptionsProperty())
                        ->default('all')
                        ->required(),
                ])
                ->disabled(fn (): bool => ! $this->canSimulate())
                ->action(fn (array $data): int => $this->simulatePublishing($data)),
            Action::make('manageCredentials')
                ->label('X.509 Credentials')
                ->icon(Heroicon::OutlinedKey)
                ->url(fn (): string => DeviceResource::getUrl('credentials', ['record' => $this->device()]))
                ->visible(fn (): bool => $this->canManageCredentials()),
            Action::make('viewDevice')
                ->label('View Device')
                ->url(fn (): string => DeviceResource::getUrl('view', ['record' => $this->getRecord()])),
        ];
    }

    public function getTitle(): string
    {
        return "Setup, Test & Control — {$this->device()->name}";
    }

    /**
     * @return array{
     *     device: array{name: string, identifier: string, client_id: string},
     *     profile: array{name: string, version: int},
     *     mqtt: array{broker_host: string, broker_port: int, use_tls: bool, security_mode: string, x509_enabled: bool, channels: list<array{key: string, label: string, direction: string, purpose: string, address: string, qos: int, retain: bool}>}|null
     * }
     */
    public function getConnectionKit(): array
    {
        return $this->connectionKit;
    }

    /** @param array<string, mixed> $data */
    public function simulatePublishing(array $data): int
    {
        $device = $this->device();
        Gate::authorize('control', $device);

        if (! $this->canSimulate()) {
            Notification::make()
                ->warning()
                ->title('Simulation unavailable')
                ->body('The device must be active and have at least one publish channel.')
                ->send();

            return 0;
        }

        $channelSelection = $data['device_channel_id'] ?? 'all';
        $deviceChannelId = is_numeric($channelSelection) ? (int) $channelSelection : null;

        if ($deviceChannelId !== null && ! $this->publishChannels()->contains('id', $deviceChannelId)) {
            Notification::make()->danger()->title('Publish channel not found')->send();

            return 0;
        }

        $dispatchCount = SimulateDevicePublishingJob::dispatchIterations(
            deviceId: $device->id,
            count: isset($data['count']) ? (int) $data['count'] : 10,
            intervalSeconds: isset($data['interval']) ? (int) $data['interval'] : 1,
            deviceChannelId: $deviceChannelId,
        );

        Notification::make()
            ->success()
            ->title('Simulation started')
            ->body("Queued {$dispatchCount} simulated telemetry message(s).")
            ->send();

        return $dispatchCount;
    }

    /** @return array<int|string, string> */
    public function getPublishChannelOptionsProperty(): array
    {
        $options = ['all' => 'All publish channels'];

        foreach ($this->publishChannels() as $channel) {
            $options[(string) $channel->id] = "{$channel->label} ({$channel->address})";
        }

        return $options;
    }

    public function canManageCredentials(): bool
    {
        return (bool) ($this->connectionKit['mqtt']['x509_enabled'] ?? false)
            && Gate::allows('manageCredentials', $this->device());
    }

    private function canSimulate(): bool
    {
        $device = $this->device();

        return ! $device->trashed()
            && $device->is_active
            && $this->publishChannels()->isNotEmpty();
    }

    /** @return Collection<int, DeviceChannel> */
    private function publishChannels(): Collection
    {
        $device = $this->device();
        $device->loadMissing('profileVersion.channels');

        return $device->profileVersion?->channels
            ?->filter(fn (DeviceChannel $channel): bool => $channel->isPublish())
            ->sortBy('sequence')
            ?? collect();
    }

    private function device(): Device
    {
        $record = $this->getRecord();
        abort_unless($record instanceof Device, 404);

        return $record;
    }
}
