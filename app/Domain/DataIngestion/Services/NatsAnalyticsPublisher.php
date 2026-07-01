<?php

declare(strict_types=1);

namespace App\Domain\DataIngestion\Services;

use App\Domain\DataIngestion\Contracts\AnalyticsPublisher;
use App\Domain\DataIngestion\Models\IngestionMessage;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Publishing\Nats\NatsPublisherFactory;
use App\Domain\DeviceProfile\Models\DeviceChannel;

class NatsAnalyticsPublisher implements AnalyticsPublisher
{
    public function __construct(
        private readonly NatsPublisherFactory $publisherFactory,
    ) {}

    /**
     * @param  array<string, mixed>  $finalValues
     */
    public function publishTelemetry(Device $device, DeviceChannel $channel, array $finalValues, IngestionMessage $ingestionMessage): void
    {
        $subject = $this->buildTelemetrySubject($device, $channel);
        $payload = [
            'ingestion_message_id' => $ingestionMessage->id,
            'organization_id' => $device->organization_id,
            'device_uuid' => $device->uuid,
            'device_external_id' => $device->external_id,
            'channel_key' => $channel->key,
            'channel_address' => $channel->address,
            'recorded_at' => now()->toIso8601String(),
            'values' => $finalValues,
        ];

        $this->publish($subject, $payload);
    }

    /**
     * @param  array<string, mixed>  $validationErrors
     */
    public function publishInvalid(Device $device, DeviceChannel $channel, array $validationErrors, IngestionMessage $ingestionMessage): void
    {
        $reason = $this->resolveInvalidReason($validationErrors);

        $subject = $this->buildInvalidSubject($device, $reason);
        $payload = [
            'ingestion_message_id' => $ingestionMessage->id,
            'organization_id' => $device->organization_id,
            'device_uuid' => $device->uuid,
            'device_external_id' => $device->external_id,
            'channel_key' => $channel->key,
            'channel_address' => $channel->address,
            'recorded_at' => now()->toIso8601String(),
            'errors' => $validationErrors,
        ];

        $this->publish($subject, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function publish(string $subject, array $payload): void
    {
        $host = config('ingestion.nats.host', '127.0.0.1');
        $port = config('ingestion.nats.port', 4223);

        $publisher = $this->publisherFactory->make(
            host: is_string($host) && $host !== '' ? $host : '127.0.0.1',
            port: is_numeric($port) ? (int) $port : 4223,
        );

        $encodedPayload = json_encode($payload);

        $publisher->publish($subject, is_string($encodedPayload) ? $encodedPayload : '{}');
    }

    private function buildTelemetrySubject(Device $device, DeviceChannel $channel): string
    {
        $prefixValue = config('ingestion.nats.analytics_subject_prefix', 'iot.v1.analytics');
        $environment = config('ingestion.subject.environment', app()->environment());
        $prefix = is_string($prefixValue) && $prefixValue !== '' ? $prefixValue : 'iot.v1.analytics';

        return implode('.', [
            trim($prefix, '.'),
            $this->sanitizeToken(is_scalar($environment) ? (string) $environment : app()->environment()),
            $this->sanitizeToken((string) $device->organization_id),
            $this->sanitizeToken($device->uuid),
            $this->sanitizeToken($channel->key),
        ]);
    }

    private function buildInvalidSubject(Device $device, string $reason): string
    {
        $prefixValue = config('ingestion.nats.invalid_subject_prefix', 'iot.v1.invalid');
        $environment = config('ingestion.subject.environment', app()->environment());
        $prefix = is_string($prefixValue) && $prefixValue !== '' ? $prefixValue : 'iot.v1.invalid';

        return implode('.', [
            trim($prefix, '.'),
            $this->sanitizeToken(is_scalar($environment) ? (string) $environment : app()->environment()),
            $this->sanitizeToken((string) $device->organization_id),
            $this->sanitizeToken($reason),
        ]);
    }

    /**
     * @param  array<string, mixed>  $validationErrors
     */
    private function resolveInvalidReason(array $validationErrors): string
    {
        foreach ($validationErrors as $error) {
            if (! is_array($error)) {
                continue;
            }

            if (($error['is_critical'] ?? false) === true) {
                return 'critical_validation';
            }
        }

        return 'validation';
    }

    private function sanitizeToken(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9_-]+/', '-', $normalized);
        $normalized = is_string($normalized) ? trim($normalized, '-') : '';

        return $normalized !== '' ? $normalized : 'unknown';
    }
}
