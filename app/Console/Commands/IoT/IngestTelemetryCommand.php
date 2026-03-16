<?php

declare(strict_types=1);

namespace App\Console\Commands\IoT;

use App\Domain\DataIngestion\DTO\IncomingTelemetryEnvelope;
use App\Domain\DataIngestion\Jobs\ProcessInboundTelemetryJob;
use App\Domain\DataIngestion\Services\DeviceSignalBindingResolver;
use App\Domain\DataIngestion\Services\DeviceTelemetryTopicResolver;
use App\Events\TelemetryIncoming;
use Basis\Nats\Client;
use Basis\Nats\Configuration;
use Basis\Nats\Message\Payload;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Laravel\Telescope\Telescope;

class IngestTelemetryCommand extends Command
{
    protected $signature = 'iot:ingest-telemetry
                            {--host= : NATS broker host}
                            {--port= : NATS broker port}
                            {--subject= : NATS subject to subscribe to}
                            {--queue= : Queue name for telemetry processing}';

    protected $description = 'Consume inbound telemetry and dispatch ingestion jobs';

    public function handle(): int
    {
        $this->disableTelescopeRecording();

        $host = $this->resolveHost();
        $port = $this->resolvePort();
        $subject = $this->resolveSubject();
        $queue = $this->resolveQueue();
        $queueConnection = $this->resolveQueueConnection();

        $this->info('Starting telemetry ingestion listener...');
        $this->info("Connecting to NATS at {$host}:{$port}");
        $this->info("Listening on subject: {$subject}");
        $this->info("Dispatching to queue connection: {$queueConnection}, queue: {$queue}");

        if ($queueConnection === 'redis' && config('database.redis.client') === 'phpredis' && ! extension_loaded('redis')) {
            $this->warn('Redis queue connection is configured, but phpredis extension is unavailable. Set INGESTION_PIPELINE_QUEUE_CONNECTION=database or install phpredis.');
        }

        $configuration = new Configuration([
            'host' => $host,
            'port' => $port,
        ]);

        $client = new Client($configuration);
        /** @var DeviceTelemetryTopicResolver $topicResolver */
        $topicResolver = app(DeviceTelemetryTopicResolver::class);
        /** @var DeviceSignalBindingResolver $bindingResolver */
        $bindingResolver = app(DeviceSignalBindingResolver::class);

        $client->subscribe($subject, function (Payload $payload) use ($bindingResolver, $queue, $queueConnection, $topicResolver): void {
            $sourceSubject = $payload->subject ?? '';
            $mqttTopic = str_replace('.', '/', $sourceSubject);

            if ($this->shouldIgnoreSubject($sourceSubject)) {
                return;
            }

            if ($topicResolver->resolve($mqttTopic) === null && ! $bindingResolver->supportsTopic($mqttTopic)) {
                return;
            }

            $decodedPayload = [];

            try {
                $decoded = json_decode($payload->body, true, flags: JSON_THROW_ON_ERROR);
                $decodedPayload = is_array($decoded) ? $decoded : [];
            } catch (\Throwable) {
                $decodedPayload = [];
            }

            $envelope = new IncomingTelemetryEnvelope(
                sourceSubject: $sourceSubject,
                mqttTopic: $mqttTopic,
                payload: $decodedPayload,
                deviceUuid: is_string(Arr::get($decodedPayload, '_meta.device_uuid')) ? Arr::get($decodedPayload, '_meta.device_uuid') : null,
                deviceExternalId: is_string(Arr::get($decodedPayload, '_meta.device_external_id')) ? Arr::get($decodedPayload, '_meta.device_external_id') : null,
                messageId: is_string($payload->headers['Nats-Msg-Id'] ?? null) ? $payload->headers['Nats-Msg-Id'] : null,
                receivedAt: now(),
            );

            event(new TelemetryIncoming(
                topic: $mqttTopic,
                deviceUuid: $envelope->deviceUuid,
                deviceExternalId: $envelope->deviceExternalId,
                payload: $decodedPayload,
                receivedAt: $envelope->resolveReceivedAt(),
            ));

            try {
                ProcessInboundTelemetryJob::dispatch($envelope->toArray())
                    ->onConnection($queueConnection)
                    ->onQueue($queue);
            } catch (\Throwable $exception) {
                $this->error("Queue dispatch failed for {$sourceSubject}: {$exception->getMessage()}");
            }
        });

        while (true) { /** @phpstan-ignore while.alwaysTrue */
            try {
                $client->process(1);
            } catch (\Throwable $exception) {
                if (str_contains($exception->getMessage(), 'No handler')) {
                    usleep(200_000);

                    continue;
                }

                $this->error("Processing error: {$exception->getMessage()}");
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

        return $this->resolveStringConfig('ingestion.nats.host', '127.0.0.1');
    }

    private function resolvePort(): int
    {
        $portOption = $this->option('port');

        if (is_numeric($portOption)) {
            return (int) $portOption;
        }

        return $this->resolveIntConfig('ingestion.nats.port', 4223);
    }

    private function resolveSubject(): string
    {
        $subjectOption = $this->option('subject');

        if (is_string($subjectOption) && trim($subjectOption) !== '') {
            return trim($subjectOption);
        }

        return $this->resolveStringConfig('ingestion.nats.subject', '>');
    }

    private function resolveQueue(): string
    {
        $queueOption = $this->option('queue');

        if (is_string($queueOption) && trim($queueOption) !== '') {
            return trim($queueOption);
        }

        return $this->resolveStringConfig('ingestion.queue', 'ingestion');
    }

    private function resolveQueueConnection(): string
    {
        return $this->resolveStringConfig('ingestion.queue_connection', $this->resolveStringConfig('queue.default', 'database'));
    }

    private function shouldIgnoreSubject(string $subject): bool
    {
        if (trim($subject) === '') {
            return true;
        }

        if (Str::startsWith($subject, ['$JS.', '$KV.', '_INBOX.', '_REQS.'])) {
            return true;
        }

        $analyticsPrefix = $this->resolveStringConfig('ingestion.nats.analytics_subject_prefix', 'iot.v1.analytics');
        $invalidPrefix = $this->resolveStringConfig('ingestion.nats.invalid_subject_prefix', 'iot.v1.invalid');

        return Str::startsWith($subject, [$analyticsPrefix, $invalidPrefix]);
    }

    private function resolveStringConfig(string $key, string $fallback): string
    {
        $value = config($key, $fallback);

        return is_string($value) && trim($value) !== '' ? $value : $fallback;
    }

    private function resolveIntConfig(string $key, int $fallback): int
    {
        $value = config($key, $fallback);

        return is_numeric($value) ? (int) $value : $fallback;
    }

    private function disableTelescopeRecording(): void
    {
        if (class_exists(Telescope::class)) {
            Telescope::stopRecording();
        }
    }
}
