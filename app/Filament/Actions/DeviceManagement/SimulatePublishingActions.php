<?php

declare(strict_types=1);

namespace App\Filament\Actions\DeviceManagement;

use App\Domain\DeviceManagement\Jobs\SimulateDevicePublishingJob;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
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
            ->modalDescription('Publish simulated telemetry messages for this device, based on the active publish topic parameters.')
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

                Radio::make('schema_version_topic_id')
                    ->label('Publish Topic')
                    ->helperText('Choose which publish topic to simulate.')
                    ->options(fn (Device $record): array => self::publishTopicOptions($record))
                    ->default('all')
                    ->required()
                    ->columns(1),
            ])
            ->disabled(fn (Device $record): bool => self::missingPublishTopicReason($record) !== null)
            ->action(function (array $data, Device $record): void {
                $topics = self::publishTopics($record);

                if ($topics->isEmpty()) {
                    Notification::make()
                        ->title('No publish topics found')
                        ->body('This device\'s schema version has no publish topics configured.')
                        ->warning()
                        ->send();

                    return;
                }

                $count = isset($data['count']) ? (int) $data['count'] : 10;
                $interval = isset($data['interval']) ? (int) $data['interval'] : 1;

                $topicSelection = $data['schema_version_topic_id'] ?? 'all';
                $schemaVersionTopicId = is_numeric($topicSelection) ? (int) $topicSelection : null;

                SimulateDevicePublishingJob::dispatchIterations(
                    deviceId: $record->id,
                    count: $count,
                    intervalSeconds: $interval,
                    schemaVersionTopicId: $schemaVersionTopicId,
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
                $records->loadMissing('schemaVersion.topics');

                $count = isset($data['count']) ? (int) $data['count'] : 10;
                $interval = isset($data['interval']) ? (int) $data['interval'] : 1;

                $queued = 0;
                $skipped = 0;

                foreach ($records as $record) {
                    if (! $record instanceof Device) {
                        continue;
                    }

                    $topics = self::publishTopics($record);

                    if ($topics->isEmpty()) {
                        $skipped++;

                        continue;
                    }

                    SimulateDevicePublishingJob::dispatchIterations(
                        deviceId: $record->id,
                        count: $count,
                        intervalSeconds: $interval,
                        schemaVersionTopicId: null,
                    );

                    $queued++;
                }

                if ($queued === 0) {
                    Notification::make()
                        ->title('No devices queued')
                        ->body('None of the selected devices have publish topics configured.')
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
     * @return SupportCollection<int, SchemaVersionTopic>
     */
    private static function publishTopics(Device $record): SupportCollection
    {
        $record->loadMissing('schemaVersion.topics');

        return $record->schemaVersion?->topics
            ?->filter(fn (SchemaVersionTopic $topic): bool => $topic->isPublish())
            ->sortBy('sequence')
                ?? collect();
    }

    /**
     * @return array<int|string, string>
     */
    private static function publishTopicOptions(Device $record): array
    {
        $topics = self::publishTopics($record);

        /** @var array<string, string> $options */
        $options = [];

        if ($topics->isEmpty()) {
            return $options;
        }

        $options['all'] = 'All publish topics';

        foreach ($topics as $topic) {
            $options[(string) $topic->id] = "{$topic->label} ({$topic->suffix})";
        }

        return $options;
    }

    private static function missingPublishTopicReason(Device $record): ?string
    {
        if ($record->getAttribute('device_schema_version_id') === null) {
            return 'Assign a schema version to this device to simulate publishing.';
        }

        if (self::publishTopics($record)->isEmpty()) {
            return 'No publish topics are configured for this schema version.';
        }

        return null;
    }
}
