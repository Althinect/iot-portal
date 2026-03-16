<?php

declare(strict_types=1);

namespace App\Domain\DeviceManagement\Publishing\Nats;

use Basis\Nats\Client;
use Basis\Nats\Configuration;
use Basis\Nats\KeyValue\Bucket;
use Illuminate\Support\Facades\Log;
use Throwable;

final class BasisNatsDeviceStateStore implements NatsDeviceStateStore
{
    private const string BUCKET_NAME = 'device-states';

    /**
     * @var array<string, Bucket>
     */
    private static array $buckets = [];

    public function store(string $deviceUuid, string $topic, array $payload, string $host = '127.0.0.1', int $port = 4223): void
    {
        try {
            $this->withBucket($host, $port, function (Bucket $bucket) use ($deviceUuid, $topic, $payload): void {
                $document = $this->normalizeDocument($bucket->get($deviceUuid));
                $document['topics'][$topic] = [
                    'topic' => $topic,
                    'payload' => $payload,
                    'stored_at' => now()->toIso8601String(),
                ];

                $data = json_encode($document);
                $payloadString = is_string($data) ? $data : '{}';

                // We bypass $bucket->put() because it has a strict :int return type hint
                // which crashes with TypeError if NATS returns null (common when JS is unstable).
                $bucket->getStream()->publish(
                    $bucket->getSubject($deviceUuid),
                    $payloadString,
                );
            });
        } catch (Throwable $exception) {
            if ($this->isJetStreamUnavailable($exception)) {
                Log::channel('device_control')->warning('NATS KV hot-state write skipped: JetStream/KV unavailable.', [
                    'bucket' => self::BUCKET_NAME,
                    'device_uuid' => $deviceUuid,
                    'topic' => $topic,
                    'error' => $exception->getMessage(),
                ]);

                return;
            }

            throw $exception;
        }
    }

    public function getLastState(string $deviceUuid, string $host = '127.0.0.1', int $port = 4223): ?array
    {
        $states = $this->getAllStates($deviceUuid, $host, $port);

        return $states === [] ? null : $states[0];
    }

    public function getAllStates(string $deviceUuid, string $host = '127.0.0.1', int $port = 4223): array
    {
        try {
            $value = $this->withBucket($host, $port, function (Bucket $bucket) use ($deviceUuid): mixed {
                return $bucket->get($deviceUuid);
            });
        } catch (Throwable $exception) {
            if ($this->isJetStreamUnavailable($exception)) {
                return [];
            }

            throw $exception;
        }

        $document = $this->normalizeDocument($value);
        $states = array_values($document['topics']);

        usort($states, function (array $left, array $right): int {
            $leftTime = strtotime($left['stored_at']) ?: 0;
            $rightTime = strtotime($right['stored_at']) ?: 0;

            return $rightTime <=> $leftTime;
        });

        return $states;
    }

    public function getStateByTopic(string $deviceUuid, string $topic, string $host = '127.0.0.1', int $port = 4223): ?array
    {
        try {
            $value = $this->withBucket($host, $port, function (Bucket $bucket) use ($deviceUuid): mixed {
                return $bucket->get($deviceUuid);
            });
        } catch (Throwable $exception) {
            if ($this->isJetStreamUnavailable($exception)) {
                return null;
            }

            throw $exception;
        }

        $document = $this->normalizeDocument($value);

        return $document['topics'][$topic] ?? null;
    }

    private function getBucket(string $host, int $port): Bucket
    {
        $key = "{$host}:{$port}";

        if (isset(self::$buckets[$key])) {
            return self::$buckets[$key];
        }

        $client = new Client(new Configuration([
            'host' => $host,
            'port' => $port,
        ]));

        $client->skipInvalidMessages(true);

        return self::$buckets[$key] = $client->getApi()->getBucket(self::BUCKET_NAME);
    }

    /**
     * @template TValue
     *
     * @param  callable(Bucket): TValue  $operation
     * @return TValue
     */
    private function withBucket(string $host, int $port, callable $operation): mixed
    {
        try {
            return $operation($this->getBucket($host, $port));
        } catch (Throwable $exception) {
            if (! $this->isStaleConnection($exception)) {
                throw $exception;
            }

            $this->forgetBucket($host, $port);

            return $operation($this->getBucket($host, $port));
        }
    }

    private function forgetBucket(string $host, int $port): void
    {
        $key = "{$host}:{$port}";

        unset(self::$buckets[$key]);
    }

    private function isJetStreamUnavailable(Throwable $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'No handler for message _REQS.')
            || str_contains($message, 'JS not enabled');
    }

    private function isStaleConnection(Throwable $exception): bool
    {
        return str_contains(strtolower($exception->getMessage()), 'stale connection');
    }

    /**
     * @return array{topics: array<string, array{topic: string, payload: array<string, mixed>, stored_at: string}>}
     */
    private function normalizeDocument(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return ['topics' => []];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($value, true);

        if (! is_array($decoded)) {
            return ['topics' => []];
        }

        $normalizedTopics = [];
        $decodedTopics = $decoded['topics'] ?? null;

        if (is_array($decodedTopics)) {
            foreach ($decodedTopics as $topic => $state) {
                $fallbackTopic = is_string($topic) ? $topic : null;
                $normalizedState = $this->normalizeTopicState($state, $fallbackTopic);

                if ($normalizedState === null) {
                    continue;
                }

                $normalizedTopics[$normalizedState['topic']] = $normalizedState;
            }

            return ['topics' => $normalizedTopics];
        }

        $legacyState = $this->normalizeTopicState($decoded);

        if ($legacyState !== null) {
            $normalizedTopics[$legacyState['topic']] = $legacyState;
        }

        return ['topics' => $normalizedTopics];
    }

    /**
     * @return array{topic: string, payload: array<string, mixed>, stored_at: string}|null
     */
    private function normalizeTopicState(mixed $state, ?string $fallbackTopic = null): ?array
    {
        if (! is_array($state)) {
            return null;
        }

        $topic = $state['topic'] ?? $fallbackTopic;

        if (! is_string($topic) || trim($topic) === '') {
            return null;
        }

        $payload = $state['payload'] ?? null;

        if (! is_array($payload)) {
            return null;
        }

        $storedAt = $state['stored_at'] ?? null;

        if (! is_string($storedAt) || trim($storedAt) === '') {
            return null;
        }

        /** @var array<string, mixed> $payload */
        return [
            'topic' => $topic,
            'payload' => $payload,
            'stored_at' => $storedAt,
        ];
    }
}
