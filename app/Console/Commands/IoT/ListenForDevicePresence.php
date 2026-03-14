<?php

declare(strict_types=1);

namespace App\Console\Commands\IoT;

use App\Domain\DeviceManagement\Services\DevicePresenceMessageHandler;
use Basis\Nats\Client;
use Basis\Nats\Configuration;
use Basis\Nats\Message\Payload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Laravel\Telescope\Telescope;

class ListenForDevicePresence extends Command
{
    protected $signature = 'iot:listen-for-device-presence
                            {--host= : NATS broker host}
                            {--port= : NATS broker port}';

    protected $description = 'Listen for device presence (LWT) messages and update connection state';

    public function handle(DevicePresenceMessageHandler $messageHandler): int
    {
        $this->disableTelescopeRecording();

        $host = $this->resolveHost();
        $port = $this->resolvePort();
        $subjectPrefix = $this->resolveSubjectPrefix();
        $subjectSuffix = $this->resolveSubjectSuffix();
        $natsSubject = $messageHandler->subscriptionSubject($subjectPrefix, $subjectSuffix);

        $this->info('Starting device presence listener...');
        $this->info("Connecting to NATS at {$host}:{$port}");
        $this->info("Listening on subject: {$natsSubject}");

        Log::channel('device_control')->info('Device presence listener starting', [
            'host' => $host,
            'port' => $port,
            'subject' => $natsSubject,
        ]);

        $configuration = new Configuration([
            'host' => $host,
            'port' => $port,
        ]);

        $client = new Client($configuration);

        $client->subscribe($natsSubject, function (Payload $payload) use ($messageHandler, $subjectPrefix, $subjectSuffix): void {
            $messageHandler->handle(
                subject: $payload->subject ?? '',
                body: $payload->body,
                prefix: $subjectPrefix,
                suffix: $subjectSuffix,
            );
        });

        $this->info('Device presence listener is running. Press Ctrl+C to stop.');
        $this->newLine();

        while (true) { /** @phpstan-ignore while.alwaysTrue */
            try {
                $client->process(1);
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), 'No handler')) {
                    usleep(200_000);

                    continue;
                }

                Log::channel('device_control')->warning('Presence listener process loop error', [
                    'error' => $e->getMessage(),
                ]);

                sleep(1);
            }
        }

        /** @phpstan-ignore deadCode.unreachable */
        return self::SUCCESS;
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

    private function resolveSubjectPrefix(): string
    {
        $prefix = config('iot.presence.subject_prefix', 'devices');

        return is_string($prefix) && trim($prefix) !== '' ? str_replace('/', '.', trim($prefix)) : 'devices';
    }

    private function resolveSubjectSuffix(): string
    {
        $suffix = config('iot.presence.subject_suffix', 'presence');

        return is_string($suffix) && trim($suffix) !== '' ? str_replace('/', '.', trim($suffix)) : 'presence';
    }

    private function disableTelescopeRecording(): void
    {
        if (class_exists(Telescope::class)) {
            Telescope::stopRecording();
        }
    }
}
