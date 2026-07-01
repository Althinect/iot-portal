<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DeviceManagement\Devices\Pages;

use App\Domain\DeviceControl\Enums\CommandStatus;
use App\Domain\DeviceControl\Models\DeviceCommandLog;
use App\Domain\DeviceControl\Services\CommandPayloadResolver;
use App\Domain\DeviceControl\Services\ControlSchemaBuilder;
use App\Domain\DeviceControl\Services\DeviceCommandDispatcher;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Publishing\Nats\NatsDeviceStateStore;
use App\Domain\DeviceManagement\ValueObjects\Protocol\MqttProtocolConfig;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Filament\Actions\DeviceManagement\ProvisionX509CertificateAction;
use App\Filament\Actions\DeviceManagement\RevokeX509CertificateAction;
use App\Filament\Actions\DeviceManagement\RotateX509CertificateAction;
use App\Filament\Actions\DeviceManagement\ViewFirmwareAction;
use App\Filament\Admin\Resources\DeviceManagement\Devices\DeviceResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CodeEditor;
use Filament\Forms\Components\CodeEditor\Enums\Language;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class DeviceControlDashboard extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = DeviceResource::class;

    protected string $view = 'filament.admin.resources.device-management.devices.pages.device-control-dashboard';

    public ?string $selectedChannelId = null;

    public ?string $commandPayloadJson = '{}';

    public bool $useAdvancedJson = false;

    /**
     * @var array<string, mixed>
     */
    public array $controlValues = [];

    /**
     * @var array<int, array{
     *     key: string,
     *     label: string,
     *     json_path: string,
     *     widget: string,
     *     type: string,
     *     required: bool,
     *     default: mixed,
     *     min: int|float|null,
     *     max: int|float|null,
     *     step: int|float,
     *     options: array<int|string, string>,
     *     unit: string|null,
     *     button_value: mixed
     * }>
     */
    public array $controlSchema = [];

    /**
     * Initial device state loaded from the NATS KV store on page mount.
     *
     * @var array{topic: string, payload: array<string, mixed>, stored_at: string}|null
     */
    public ?array $initialDeviceState = null;

    /**
     * Initial per-channel device states loaded from the NATS KV store on page mount.
     *
     * @var array<int, array{topic: string, payload: array<string, mixed>, stored_at: string}>
     */
    public array $initialDeviceStates = [];

    public ?string $deviceConnectionState = null;

    public static function getNavigationLabel(): string
    {
        return 'Control Dashboard';
    }

    public function getMaxContentWidth(): string
    {
        return 'full';
    }

    public function getHeaderActions(): array
    {
        return [
            Action::make('viewDevice')
                ->label('View Device')
                ->url(fn (): string => DeviceResource::getUrl('view', ['record' => $this->getRecord()])),
            ViewFirmwareAction::make(),
            ProvisionX509CertificateAction::make(),
            RotateX509CertificateAction::make(),
            RevokeX509CertificateAction::make(),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(3)->schema([
                    Group::make([
                        Radio::make('selectedChannelId')
                            ->label('Command Channel')
                            ->options(fn (): array => $this->getSubscribeChannelOptionsProperty())
                            ->required()
                            ->live()
                            ->afterStateUpdated(function (Set $set): void {
                                $this->loadDefaultPayload();
                                $set('commandPayloadJson', $this->commandPayloadJson);
                            }),

                        TextEntry::make('mqttTopic')
                            ->label('MQTT Topic')
                            ->state(fn (): string => $this->getResolvedMqttTopic())
                            ->copyable()
                            ->fontFamily('mono'),

                        TextEntry::make('natsSubject')
                            ->label('NATS Subject')
                            ->state(fn (): string => $this->getResolvedNatsSubject())
                            ->copyable()
                            ->fontFamily('mono'),

                        TextEntry::make('mqttSettings')
                            ->label('QoS / Retain')
                            ->state(fn (): string => $this->getMqttSettings()),
                    ])->columnSpan(1),

                    Group::make([
                        Checkbox::make('useAdvancedJson')
                            ->label('Advanced JSON mode')
                            ->helperText('Enable to edit and send raw JSON payloads directly.')
                            ->live(),

                        CodeEditor::make('commandPayloadJson')
                            ->hiddenLabel()
                            ->language(Language::Json)
                            ->wrap()
                            ->required()
                            ->markAsRequired(false)
                            ->visible(fn (): bool => $this->useAdvancedJson),

                        Placeholder::make('controls')
                            ->hiddenLabel()
                            ->view('filament.admin.resources.device-management.devices.partials.control-widgets')
                            ->visible(fn (): bool => ! $this->useAdvancedJson),

                        Placeholder::make('send_button')
                            ->hiddenLabel()
                            ->view('filament.admin.resources.device-management.devices.partials.send-button'),
                        // ->visible(fn (): bool => $this->useAdvancedJson),
                    ])->columnSpan(2),
                ]),
            ]);
    }

    public function getTitle(): string
    {
        /** @var Device $device */
        $device = $this->getRecord();

        return "Control Dashboard — {$device->name}";
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        /** @var Device $device */
        $device = $this->getRecord();

        $device->loadMissing('profileVersion.channels.parameters');

        $this->deviceConnectionState = $device->effectiveConnectionState();

        $this->loadDefaultPayload();
        $this->loadInitialDeviceState();
        $this->applyInitialStateToControlValues();
    }

    public function loadDefaultPayload(): void
    {
        $channels = $this->getSubscribeChannels();

        if ($channels->isEmpty()) {
            $this->commandPayloadJson = '{}';
            $this->controlSchema = [];
            $this->controlValues = [];

            return;
        }

        $channel = $this->selectedChannelId
            ? $channels->firstWhere('id', (int) $this->selectedChannelId)
            : $channels->first();

        if ($channel) {
            $this->selectedChannelId = (string) $channel->id;

            /** @var ControlSchemaBuilder $builder */
            $builder = app(ControlSchemaBuilder::class);
            $this->controlSchema = $builder->buildForChannel($channel);
            $this->controlValues = $builder->defaultControlValues($channel);
            $this->syncAdvancedJsonFromControls();
            $this->normalizeControlValuesForUi();
        }
    }

    public function sendButtonCommand(string $parameterKey): void
    {
        if ($parameterKey === '') {
            return;
        }

        $buttonControl = null;

        foreach ($this->controlSchema as $control) {
            if ($control['key'] !== $parameterKey) {
                continue;
            }

            $buttonControl = $control;
            break;
        }

        if ($buttonControl === null || $buttonControl['widget'] !== 'button') {
            return;
        }

        $this->controlValues[$parameterKey] = $buttonControl['button_value'] ?? true;
        $this->useAdvancedJson = false;

        $this->sendCommand();
    }

    public function sendCommand(): void
    {
        if (! $this->selectedChannelId) {
            Notification::make()
                ->title('No channel selected')
                ->body('Please select a command channel first.')
                ->warning()
                ->send();

            return;
        }

        $channel = DeviceChannel::query()->with('parameters')->find((int) $this->selectedChannelId);

        if (! $channel) {
            Notification::make()
                ->title('Channel not found')
                ->danger()
                ->send();

            return;
        }

        /** @var CommandPayloadResolver $payloadResolver */
        $payloadResolver = app(CommandPayloadResolver::class);

        $payload = [];
        $errors = [];

        if ($this->useAdvancedJson) {
            /** @var mixed $decodedPayload */
            $decodedPayload = json_decode($this->commandPayloadJson ?? '{}', true);

            if (! is_array($decodedPayload)) {
                Notification::make()
                    ->title('Invalid JSON')
                    ->body('The command payload is not valid JSON.')
                    ->danger()
                    ->send();

                return;
            }

            $payload = $this->normalizePayloadArray($decodedPayload);
            $errors = $payloadResolver->validatePayload($channel, $payload);
        } else {
            $resolved = $payloadResolver->resolveFromControls($channel, $this->controlValues);
            $payload = $resolved['payload'];
            $errors = $resolved['errors'];
        }

        if ($errors !== []) {
            Notification::make()
                ->title('Invalid control values')
                ->body($this->formatValidationErrors($errors))
                ->danger()
                ->send();

            return;
        }

        $this->commandPayloadJson = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';

        /** @var Device $device */
        $device = $this->getRecord();

        /** @var DeviceCommandDispatcher $dispatcher */
        $dispatcher = app(DeviceCommandDispatcher::class);

        $commandLog = $dispatcher->dispatch(
            device: $device,
            channel: $channel,
            payload: $payload,
            userId: is_int(auth()->id()) ? auth()->id() : null,
        );

        if ($commandLog->status === CommandStatus::Failed) { /** @phpstan-ignore identical.alwaysFalse */
            Notification::make()
                ->title('Command failed')
                ->body($commandLog->error_message ?? 'Failed to publish command.')
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Command sent')
            ->body("Published to {$channel->address}")
            ->success()
            ->send();
    }

    public function updateDeviceConnectionState(string $state): void
    {
        $this->deviceConnectionState = $state;
    }

    public function table(Table $table): Table
    {
        /** @var Device $device */
        $device = $this->getRecord();

        return $table
            ->query(
                DeviceCommandLog::query()
                    ->where('device_id', $device->id)
                    ->latest()
            )
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),

                TextColumn::make('channel.address')
                    ->label('Channel'),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (CommandStatus $state): string => match ($state) {
                        CommandStatus::Pending => 'gray',
                        CommandStatus::Sent => 'info',
                        CommandStatus::Acknowledged => 'warning',
                        CommandStatus::Completed => 'success',
                        CommandStatus::Failed => 'danger',
                        CommandStatus::Timeout => 'warning',
                    }),

                TextColumn::make('command_payload')
                    ->label('Payload')
                    ->formatStateUsing(fn ($state): string => is_array($state) ? (string) json_encode($state) : (string) $state)
                    ->limit(60),

                TextColumn::make('sent_at')
                    ->label('Sent')
                    ->dateTime()
                    ->placeholder('—'),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime(),
            ])
            ->defaultSort('id', 'desc')
            ->poll('5s');
    }

    private function getSelectedChannel(): ?DeviceChannel
    {
        if (! $this->selectedChannelId) {
            return null;
        }

        return $this->getSubscribeChannels()->firstWhere('id', (int) $this->selectedChannelId);
    }

    public function getResolvedMqttTopic(): string
    {
        $channel = $this->getSelectedChannel();

        if (! $channel) {
            return '—';
        }

        /** @var Device $device */
        $device = $this->getRecord();

        $identifier = $device->external_id ?: $device->uuid;
        $protocolConfig = $device->profileVersion?->protocol_config;
        $baseTopic = $protocolConfig instanceof MqttProtocolConfig ? $protocolConfig->getBaseTopic() : 'device';

        return trim($baseTopic, '/').'/'.$identifier.'/'.$channel->address;
    }

    public function getResolvedNatsSubject(): string
    {
        return str_replace('/', '.', $this->getResolvedMqttTopic());
    }

    public function getMqttSettings(): string
    {
        $channel = $this->getSelectedChannel();

        if (! $channel) {
            return '—';
        }

        $qos = $channel->qos ?? 0;
        $retain = ($channel->retain ?? false) ? 'Yes' : 'No';

        return "QoS {$qos} · Retain: {$retain}";
    }

    /**
     * @return Collection<int, DeviceChannel>
     */
    private function getSubscribeChannels(): Collection
    {
        /** @var Device $device */
        $device = $this->getRecord();

        $device->loadMissing('profileVersion.channels.parameters');

        return $device->profileVersion?->channels
            ?->filter(fn (DeviceChannel $channel): bool => $channel->isSubscribe())
            ->sortBy('sequence')
                ?? collect();
    }

    /**
     * @return array<int|string, string>
     */
    public function getSubscribeChannelOptionsProperty(): array
    {
        $options = [];

        foreach ($this->getSubscribeChannels() as $channel) {
            $options[(string) $channel->id] = "{$channel->label} ({$channel->address})";
        }

        return $options;
    }

    public function getDeviceUuidProperty(): string
    {
        /** @var Device $device */
        $device = $this->getRecord();

        return $device->uuid;
    }

    /**
     * Update control widget values from an incoming device state payload.
     *
     * Called via WebSocket when the device publishes new state, so the
     * control sliders/toggles/selects reflect the device's actual values.
     *
     * @param  array<string, mixed>  $payload
     */
    public function updateControlValuesFromState(array $payload): void
    {
        $payload = $this->extractControlPayload($payload);
        $changed = false;

        foreach ($this->controlSchema as $control) {
            $key = $control['key'];

            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $value = $payload[$key];

            if ($control['widget'] === 'toggle') {
                $value = (bool) $value;
            } elseif (in_array($control['widget'], ['slider', 'number'], true)) {
                $value = is_numeric($value) ? $value + 0 : $value;
            }

            $this->controlValues[$key] = $value;
            $changed = true;
        }

        if ($changed) {
            $this->normalizeControlValuesForUi();
            $this->syncAdvancedJsonFromControls();
        }
    }

    private function loadInitialDeviceState(): void
    {
        /** @var Device $device */
        $device = $this->getRecord();

        try {
            /** @var NatsDeviceStateStore $stateStore */
            $stateStore = app(NatsDeviceStateStore::class);
            $host = config('iot.nats.host', '127.0.0.1');
            $port = config('iot.nats.port', 4223);
            $resolvedHost = is_string($host) && trim($host) !== '' ? trim($host) : '127.0.0.1';
            $resolvedPort = is_numeric($port) ? (int) $port : 4223;

            $this->initialDeviceStates = $stateStore->getAllStates(
                $device->uuid,
                $resolvedHost,
                $resolvedPort,
            );
            $this->initialDeviceState = $this->initialDeviceStates[0] ?? null;
        } catch (\Throwable) {
            $this->initialDeviceStates = [];
            $this->initialDeviceState = null;
        }
    }

    /**
     * Pre-populate control values from the last known device state loaded from NATS KV.
     */
    private function applyInitialStateToControlValues(): void
    {
        if ($this->initialDeviceStates === []) {
            return;
        }

        foreach ($this->initialDeviceStates as $state) {
            $payload = $this->extractControlPayload($state['payload']);

            if ($payload === []) {
                continue;
            }

            foreach ($this->controlSchema as $control) {
                $key = $control['key'];

                if (! array_key_exists($key, $payload)) {
                    continue;
                }

                $value = $payload[$key];

                if ($control['widget'] === 'toggle') {
                    $value = (bool) $value;
                } elseif (in_array($control['widget'], ['slider', 'number'], true)) {
                    $value = is_numeric($value) ? $value + 0 : $value;
                }

                $this->controlValues[$key] = $value;
            }
        }

        $this->normalizeControlValuesForUi();
    }

    /**
     * Normalize known device-state payload shapes for control hydration.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function extractControlPayload(array $payload): array
    {
        $values = $payload['values'] ?? null;

        if (is_array($values)) {
            /** @var array<string, mixed> $values */
            return $values;
        }

        return $payload;
    }

    /**
     * @param  array<string, string>  $errors
     */
    private function formatValidationErrors(array $errors): string
    {
        $messages = [];

        foreach ($errors as $key => $message) {
            $messages[] = "{$key}: {$message}";
        }

        return implode("\n", $messages);
    }

    private function syncAdvancedJsonFromControls(): void
    {
        $channel = $this->getSelectedChannel();

        if (! $channel) {
            return;
        }

        /** @var CommandPayloadResolver $payloadResolver */
        $payloadResolver = app(CommandPayloadResolver::class);
        $resolved = $payloadResolver->resolveFromControls($channel, $this->controlValues);

        $this->commandPayloadJson = json_encode(
            $resolved['payload'],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ) ?: '{}';
    }

    private function normalizeControlValuesForUi(): void
    {
        foreach ($this->controlSchema as $control) {
            if ($control['widget'] !== 'json') {
                continue;
            }

            $key = $control['key'];

            if (! array_key_exists($key, $this->controlValues)) {
                continue;
            }

            $current = $this->controlValues[$key];

            if (is_array($current)) {
                $this->controlValues[$key] = json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
            }
        }
    }

    /**
     * @param  array<int|string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizePayloadArray(array $payload): array
    {
        $normalized = [];

        foreach ($payload as $key => $value) {
            $normalizedKey = is_string($key) ? $key : (string) $key;
            $normalized[$normalizedKey] = is_array($value)
                ? $this->normalizePayloadArray($value)
                : $value;
        }

        return $normalized;
    }
}
