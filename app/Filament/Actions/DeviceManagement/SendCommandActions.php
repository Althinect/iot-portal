<?php

declare(strict_types=1);

namespace App\Filament\Actions\DeviceManagement;

use App\Domain\DeviceControl\Enums\CommandStatus;
use App\Domain\DeviceControl\Services\DeviceCommandDispatcher;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

final class SendCommandActions
{
    public static function recordAction(): Action
    {
        return Action::make('sendCommand')
            ->label('Send Command')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->modalHeading('Send Command to Device')
            ->modalDescription('Select a command channel and send a JSON command payload to the device.')
            ->tooltip(fn (Device $record): ?string => self::missingCommandChannelReason($record))
            ->schema([
                Radio::make('device_channel_id')
                    ->label('Command Channel')
                    ->helperText('Choose which command channel to publish to.')
                    ->options(fn (Device $record): array => self::commandChannelOptions($record))
                    ->required()
                    ->live()
                    ->columns(1),

                Textarea::make('command_payload_json')
                    ->label('Command Payload (JSON)')
                    ->helperText('Edit the JSON payload before sending. The template is pre-filled from the schema defaults.')
                    ->rows(12)
                    ->extraAttributes(['class' => 'font-mono text-sm'])
                    ->default(fn (Device $record): string => self::defaultPayloadJson($record))
                    ->required(),
            ])
            ->disabled(fn (Device $record): bool => self::missingCommandChannelReason($record) !== null)
            ->action(function (array $data, Device $record): void {
                $channelId = isset($data['device_channel_id']) ? (int) $data['device_channel_id'] : null;
                $payloadJson = $data['command_payload_json'] ?? '{}';

                $channel = DeviceChannel::find($channelId);

                if (! $channel) {
                    Notification::make()
                        ->title('Channel not found')
                        ->body('The selected command channel could not be found.')
                        ->danger()
                        ->send();

                    return;
                }

                /** @var array<string, mixed>|null $decodedPayload */
                $decodedPayload = json_decode($payloadJson, true);

                if (! is_array($decodedPayload)) {
                    Notification::make()
                        ->title('Invalid JSON')
                        ->body('The command payload is not valid JSON.')
                        ->danger()
                        ->send();

                    return;
                }

                /** @var DeviceCommandDispatcher $dispatcher */
                $dispatcher = app(DeviceCommandDispatcher::class);

                $commandLog = $dispatcher->dispatch(
                    device: $record,
                    channel: $channel,
                    payload: $decodedPayload,
                    userId: is_int(auth()->id()) ? auth()->id() : null,
                );

                if ($commandLog->status === CommandStatus::Failed) { /** @phpstan-ignore identical.alwaysFalse */
                    Notification::make()
                        ->title('Command failed')
                        ->body($commandLog->error_message ?? 'Failed to publish command to NATS.')
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Command sent')
                    ->body("Command published to {$channel->address}. Check the dashboard for real-time status.")
                    ->success()
                    ->send();
            });
    }

    private static function defaultPayloadJson(Device $record): string
    {
        $channels = self::commandChannels($record);

        if ($channels->isEmpty()) {
            return '{}';
        }

        $firstChannel = $channels->first();
        $template = $firstChannel instanceof DeviceChannel
            ? $firstChannel->parameters->where('is_active', true)->sortBy('sequence')->reduce(
                fn (array $payload, $parameter): array => $parameter->placeValue($payload, $parameter->resolvedDefaultValue()),
                []
            )
            : [];

        return json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * @return Collection<int, DeviceChannel>
     */
    private static function commandChannels(Device $record): Collection
    {
        $record->loadMissing('profileVersion.channels.parameters');

        return $record->profileVersion?->channels
            ?->filter(fn (DeviceChannel $channel): bool => $channel->isPurposeCommand())
            ->sortBy('sequence')
                ?? collect();
    }

    /**
     * @return array<int|string, string>
     */
    private static function commandChannelOptions(Device $record): array
    {
        $channels = self::commandChannels($record);
        $options = [];

        foreach ($channels as $channel) {
            $options[(string) $channel->id] = "{$channel->label} ({$channel->address})";
        }

        return $options;
    }

    private static function missingCommandChannelReason(Device $record): ?string
    {
        if ($record->getAttribute('device_profile_version_id') === null) {
            return 'Assign a profile version to this device to send commands.';
        }

        if (self::commandChannels($record)->isEmpty()) {
            return 'No command channels are configured for this profile version.';
        }

        return null;
    }
}
