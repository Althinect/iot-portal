<?php

declare(strict_types=1);

namespace App\Console\Commands\IoT;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Publishing\Nats\NatsDeviceStateStore;
use App\Domain\DeviceManagement\Publishing\Nats\NatsPublisherFactory;
use App\Domain\DeviceManagement\ValueObjects\Protocol\MqttProtocolConfig;
use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use App\Events\DeviceStateReceived;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\search;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;

class ManualDevicePublishCommand extends Command
{
    protected $signature = 'iot:manual-publish {device_uuid? : The UUID of the device to publish state for (optional)}
                            {--host= : NATS broker host}
                            {--port= : NATS broker port}';

    protected $description = 'Simulate a manual device state change — the device publishes updated state to the broker';

    public function handle(): int
    {
        $uuid = $this->argument('device_uuid');
        $host = $this->resolveHost();
        $port = $this->resolvePort();

        // If no UUID provided, let user search and select a device
        if (! $uuid) {
            $device = $this->searchAndSelectDevice();
            if (! $device) {
                $this->error('No device selected.');

                return 1;
            }
        } else {
            if (! Str::isUuid($uuid)) {
                $this->error("Device with UUID {$uuid} not found.");

                return 1;
            }

            /** @var Device|null $device */
            $device = Device::query()
                ->where('uuid', $uuid)
                ->with(['profileVersion.channels.parameters'])
                ->first();

            if (! $device) {
                $this->error("Device with UUID {$uuid} not found.");

                return 1;
            }
        }

        $publishChannels = $device->profileVersion?->channels
            ?->filter(fn (DeviceChannel $channel): bool => $channel->isPublish())
            ->sortBy('sequence');

        if (! $publishChannels || $publishChannels->isEmpty()) {
            $this->error('No publish channels found for this device profile.');

            return 1;
        }

        intro("Manual State Publish — {$device->name}");

        $channelOptions = $publishChannels
            ->mapWithKeys(fn (DeviceChannel $channel): array => [(string) $channel->id => "{$channel->label} ({$channel->address})"])
            ->all();

        /** @var string $selectedChannelId */
        $selectedChannelId = select(
            label: 'Which publish channel?',
            options: $channelOptions,
        );

        /** @var DeviceChannel|null $selectedChannel */
        $selectedChannel = $publishChannels->firstWhere('id', (int) $selectedChannelId);

        if (! $selectedChannel) {
            $this->error('Selected channel not found.');

            return 1;
        }

        $selectedChannel->loadMissing('parameters');

        /** @var Collection<int, ProfileParameterDefinition> $parameters */
        $parameters = $selectedChannel->parameters
            ->where('is_active', true)
            ->sortBy('sequence');

        if ($parameters->isEmpty()) {
            $this->warn('No active parameters for this channel. Publishing empty payload.');
        }

        $parameterValues = $this->collectParameterValues($parameters);

        $payload = [];
        foreach ($parameters as $parameter) {
            $value = $parameterValues[$parameter->key] ?? $parameter->resolvedDefaultValue();
            $payload = $parameter->placeValue($payload, $value);
        }

        $protocolConfig = $device->profileVersion?->protocol_config;
        $baseTopic = $protocolConfig instanceof MqttProtocolConfig ? $protocolConfig->getBaseTopic() : 'device';
        $identifier = $device->external_id ?: $device->uuid;
        $mqttTopic = trim($baseTopic, '/').'/'.$identifier.'/'.trim($selectedChannel->address, '/');
        $natsSubject = str_replace('/', '.', $mqttTopic);

        table(
            headers: ['Property', 'Value'],
            rows: [
                ['Device', $device->name],
                ['MQTT Topic', $mqttTopic],
                ['NATS Subject', $natsSubject],
                ['Payload', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}'],
            ],
        );

        $encodedPayload = json_encode($payload);
        $encodedPayload = is_string($encodedPayload) ? $encodedPayload : '{}';

        spin(
            message: 'Publishing state to NATS broker...',
            callback: function () use ($host, $port, $natsSubject, $encodedPayload): void {
                /** @var NatsPublisherFactory $factory */
                $factory = app(NatsPublisherFactory::class);
                $publisher = $factory->make($host, $port);
                $publisher->publish($natsSubject, $encodedPayload);
            },
        );

        event(new DeviceStateReceived(
            topic: $mqttTopic,
            deviceUuid: $device->uuid,
            deviceExternalId: $device->external_id,
            payload: $payload,
        ));

        try {
            /** @var NatsDeviceStateStore $stateStore */
            $stateStore = app(NatsDeviceStateStore::class);
            $stateStore->store($device->uuid, $mqttTopic, $payload, $host, $port);
        } catch (\Throwable $e) {
            $this->warn("Could not persist state to KV: {$e->getMessage()}");
        }

        outro('Device state published successfully.');

        return 0;
    }

    private function resolveHost(): string
    {
        $hostOption = $this->option('host');

        if (is_string($hostOption) && trim($hostOption) !== '') {
            return trim($hostOption);
        }

        $host = config('iot.nats.host', '127.0.0.1');

        return is_string($host) && trim($host) !== '' ? trim($host) : '127.0.0.1';
    }

    private function resolvePort(): int
    {
        $portOption = $this->option('port');

        if (is_numeric($portOption)) {
            return (int) $portOption;
        }

        $port = config('iot.nats.port', 4223);

        return is_numeric($port) ? (int) $port : 4223;
    }

    /**
     * Search and select a device by name or external ID.
     */
    private function searchAndSelectDevice(): ?Device
    {
        /** @var string|int $deviceId */
        $deviceId = search(
            label: 'Search for a device (by name or ID)',
            options: function (string $value) {
                if (strlen($value) === 0) {
                    return Device::query()
                        ->limit(10)
                        ->orderBy('name')
                        ->get()
                        ->mapWithKeys(fn (Device $d): array => [
                            $d->id => "{$d->name} ({$d->external_id})",
                        ])
                        ->all();
                }

                return Device::query()
                    ->where('name', 'like', "%{$value}%")
                    ->orWhere('external_id', 'like', "%{$value}%")
                    ->limit(10)
                    ->orderBy('name')
                    ->get()
                    ->mapWithKeys(fn (Device $d): array => [
                        $d->id => "{$d->name} ({$d->external_id})",
                    ])
                    ->all();
            },
            placeholder: 'Type to search...',
        );

        return Device::query()
            ->where('id', $deviceId)
            ->with(['profileVersion.channels.parameters'])
            ->first();
    }

    /**
     * Prompt the user for each parameter value using Laravel Prompts form.
     *
     * @param  Collection<int, ProfileParameterDefinition>  $parameters
     * @return array<string, mixed>
     */
    private function collectParameterValues(Collection $parameters): array
    {
        if ($parameters->isEmpty()) {
            return [];
        }

        $typed = [];

        foreach ($parameters as $parameter) {
            $label = "{$parameter->key} ({$parameter->type->value})";
            $default = $this->formatDefaultForPrompt($parameter);

            if ($parameter->type === ParameterDataType::Boolean) {
                $raw = \Laravel\Prompts\confirm(
                    label: $label,
                    default: (bool) $parameter->resolvedDefaultValue(),
                );
            } else {
                $raw = text(
                    label: $label,
                    default: $default,
                    required: $parameter->required,
                    validate: fn (string $value): ?string => $this->validateParameterInput($parameter, $value),
                );
            }

            $typed[$parameter->key] = $this->castParameterValue($parameter, $raw);
        }

        return $typed;
    }

    private function formatDefaultForPrompt(ProfileParameterDefinition $parameter): string
    {
        $default = $parameter->resolvedDefaultValue();

        if (is_array($default)) {
            return json_encode($default) ?: '{}';
        }

        return is_scalar($default) ? (string) $default : '';
    }

    private function validateParameterInput(ProfileParameterDefinition $parameter, string $value): ?string
    {
        return match ($parameter->type) {
            ParameterDataType::Integer => preg_match('/^-?\d+$/', $value) === 1 ? null : 'Must be an integer.',
            ParameterDataType::Decimal => is_numeric($value) ? null : 'Must be a number.',
            default => null,
        };
    }

    private function castParameterValue(ProfileParameterDefinition $parameter, mixed $raw): mixed
    {
        $stringValue = is_string($raw) ? $raw : (is_scalar($raw) ? (string) $raw : '');

        return match ($parameter->type) {
            ParameterDataType::Integer => (int) $stringValue,
            ParameterDataType::Decimal => (float) $stringValue,
            ParameterDataType::Boolean => is_bool($raw) ? $raw : in_array($raw, ['true', '1', 1], true),
            ParameterDataType::Json => is_array($raw) ? $raw : (json_decode($stringValue, true) ?? []),
            ParameterDataType::String => $stringValue,
        };
    }
}
