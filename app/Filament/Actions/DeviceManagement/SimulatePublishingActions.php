<?php

declare(strict_types=1);

namespace App\Filament\Actions\DeviceManagement;

use App\Domain\DeviceManagement\Jobs\SimulateDevicePublishingJob;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

final class SimulatePublishingActions
{
    public static function recordAction(): Action
    {
        return Action::make('simulatePublishing')
            ->label('Simulate Publishing')
            ->icon(Heroicon::OutlinedPlay)
            ->modalHeading('Simulate Publishing')
            ->modalDescription('Publish simulated telemetry messages for this device, based on the active publish channel parameters.')
            ->tooltip(fn (Device $record): ?string => self::missingPublishTopicReason($record))
            ->schema([
                TextInput::make('count')
                    ->label('Iterations')
                    ->helperText('How many data points to publish.')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(500)
                    ->default(10)
                    ->required(),

                TextInput::make('interval')
                    ->label('Interval (seconds)')
                    ->helperText('Seconds to wait between each iteration (0 = no delay).')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(60)
                    ->default(1)
                    ->required(),

                Radio::make('device_channel_id')
                    ->label('Publish Channel')
                    ->helperText('Choose which publish channel to simulate.')
                    ->options(fn (Device $record): array => self::publishChannelOptions($record))
                    ->default('all')
                    ->required()
                    ->columns(1),
            ])
            ->disabled(fn (Device $record): bool => self::missingPublishTopicReason($record) !== null)
            ->action(function (array $data, Device $record): void {
                $channels = self::publishChannels($record);

                if ($channels->isEmpty()) {
                    Notification::make()
                        ->title('No publish channels found')
                        ->body('This device profile version has no publish channels configured.')
                        ->warning()
                        ->send();

                    return;
                }

                $count = isset($data['count']) ? (int) $data['count'] : 10;
                $interval = isset($data['interval']) ? (int) $data['interval'] : 1;

                $channelSelection = $data['device_channel_id'] ?? 'all';
                $deviceChannelId = is_numeric($channelSelection) ? (int) $channelSelection : null;

                SimulateDevicePublishingJob::dispatchIterations(
                    deviceId: $record->id,
                    count: $count,
                    intervalSeconds: $interval,
                    deviceChannelId: $deviceChannelId,
                );

                Notification::make()
                    ->title('Simulation started')
                    ->body('Publishing simulation has been queued and will run shortly.')
                    ->success()
                    ->send();
            });
    }

    public static function bulkAction(): BulkAction
    {
        return BulkAction::make('simulatePublishingBulk')
            ->label('Simulate Devices')
            ->icon(Heroicon::OutlinedPlay)
            ->modalHeading('Simulate Devices')
            ->modalSubheading('Publish simulated telemetry messages for each selected device.')
            ->schema([
                TextInput::make('count')
                    ->label('Iterations')
                    ->helperText('How many data points to publish per device.')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(500)
                    ->default(10)
                    ->required(),

                TextInput::make('interval')
                    ->label('Interval (seconds)')
                    ->helperText('Seconds to wait between each iteration (0 = no delay).')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(60)
                    ->default(1)
                    ->required(),
            ])
            ->requiresConfirmation()
            ->action(function (Collection $records, array $data): void {
                $records->loadMissing('profileVersion.channels');

                $count = isset($data['count']) ? (int) $data['count'] : 10;
                $interval = isset($data['interval']) ? (int) $data['interval'] : 1;

                $queued = 0;
                $skipped = 0;

                foreach ($records as $record) {
                    if (! $record instanceof Device) {
                        continue;
                    }

                    $channels = self::publishChannels($record);

                    if ($channels->isEmpty()) {
                        $skipped++;

                        continue;
                    }

                    SimulateDevicePublishingJob::dispatchIterations(
                        deviceId: $record->id,
                        count: $count,
                        intervalSeconds: $interval,
                        deviceChannelId: null,
                    );

                    $queued++;
                }

                if ($queued === 0) {
                    Notification::make()
                        ->title('No devices queued')
                        ->body('None of the selected devices have publish channels configured.')
                        ->warning()
                        ->send();

                    return;
                }

                $body = "Queued {$queued} device(s) for simulation.";

                if ($skipped > 0) {
                    $body .= " Skipped {$skipped} device(s) without publish topics.";
                }

                Notification::make()
                    ->title('Simulation queued')
                    ->body($body)
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * @return SupportCollection<int, DeviceChannel>
     */
    private static function publishChannels(Device $record): SupportCollection
    {
        $record->loadMissing('profileVersion.channels');

        return $record->profileVersion?->channels
            ?->filter(fn (DeviceChannel $channel): bool => $channel->isPublish())
            ->sortBy('sequence')
                ?? collect();
    }

    /**
     * @return array<int|string, string>
     */
    private static function publishChannelOptions(Device $record): array
    {
        $channels = self::publishChannels($record);

        /** @var array<string, string> $options */
        $options = [];

        if ($channels->isEmpty()) {
            return $options;
        }

        $options['all'] = 'All publish channels';

        foreach ($channels as $channel) {
            $options[(string) $channel->id] = "{$channel->label} ({$channel->address})";
        }

        return $options;
    }

    private static function missingPublishTopicReason(Device $record): ?string
    {
        if ($record->getAttribute('device_profile_version_id') === null) {
            return 'Assign a profile version to this device to simulate publishing.';
        }

        if (self::publishChannels($record)->isEmpty()) {
            return 'No publish channels are configured for this profile version.';
        }

        return null;
    }
}
