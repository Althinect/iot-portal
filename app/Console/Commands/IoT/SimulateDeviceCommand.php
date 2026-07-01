<?php

declare(strict_types=1);

namespace App\Console\Commands\IoT;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Publishing\DevicePublishingSimulator;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use Illuminate\Console\Command;

class SimulateDeviceCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'iot:simulate {device_uuid : The UUID of the device to simulate}
                            {--count=10 : Number of data points to generate}
                            {--interval=1 : Seconds between each message}
                            {--host= : NATS broker host}
                            {--port= : NATS broker port}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Simulate IoT device telemetry publishing to NATS/MQTT';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $uuid = $this->argument('device_uuid');
        $count = (int) $this->option('count');
        $interval = (int) $this->option('interval');
        $host = $this->resolveHost();
        $port = $this->resolvePort();

        $device = Device::where('uuid', $uuid)->first();

        if (! $device) {
            $this->error("Device with UUID {$uuid} not found.");

            return 1;
        }

        $device->loadMissing('profileVersion.channels');

        $this->info("Starting simulation for: {$device->name} ({$device->uuid})");

        $publishChannels = $device->profileVersion?->channels
            ?->filter(fn (DeviceChannel $channel): bool => $channel->isPublish());

        if (! $publishChannels || $publishChannels->isEmpty()) {
            $this->error('No publish channels found for this device profile.');

            return 1;
        }

        /** @var DevicePublishingSimulator $simulator */
        $simulator = app(DevicePublishingSimulator::class);

        $simulator->simulate(
            device: $device,
            count: $count,
            intervalSeconds: $interval,
            host: $host,
            port: $port,
            onBeforePublish: function (int $iteration, string $mqttTopic, array $payload, DeviceChannel $channel) use ($count): void {
                $this->comment("Generating data point {$iteration}/{$count}...");
                $this->line("  Topic: <info>{$mqttTopic}</info>");
                $this->line('  Payload: '.json_encode($payload, JSON_PRETTY_PRINT));
            },
            onPublishFailed: function (int $iteration, string $mqttTopic, \Throwable $exception, DeviceChannel $channel): void {
                $this->error("  Failed to publish on iteration {$iteration} for {$mqttTopic}: {$exception->getMessage()}");
            },
        );

        $this->info('Simulation complete.');

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

    // Simulation implementation lives in DevicePublishingSimulator so it can be reused by Filament actions.
}
