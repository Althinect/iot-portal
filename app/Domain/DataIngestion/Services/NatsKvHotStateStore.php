<?php

declare(strict_types=1);

namespace App\Domain\DataIngestion\Services;

use App\Domain\DataIngestion\Contracts\HotStateStore;
use App\Domain\DataIngestion\Models\IngestionMessage;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Publishing\Nats\NatsDeviceStateStore;
use App\Domain\DeviceManagement\ValueObjects\Protocol\MqttProtocolConfig;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;

class NatsKvHotStateStore implements HotStateStore
{
    public function __construct(
        private readonly NatsDeviceStateStore $stateStore,
    ) {}

    /**
     * @param  array<string, mixed>  $finalValues
     */
    public function store(
        Device $device,
        DeviceChannel $channel,
        array $finalValues,
        IngestionMessage $ingestionMessage,
        ?DeviceTelemetryLog $telemetryLog = null,
    ): void {
        $mqttTopic = $this->resolvedChannelTopic($device, $channel);
        $status = $ingestionMessage->status;
        $recordedAt = $telemetryLog?->recorded_at ?? $ingestionMessage->received_at ?? now();
        $host = config('ingestion.nats.host', '127.0.0.1');
        $port = config('ingestion.nats.port', 4223);

        $this->stateStore->store(
            deviceUuid: $device->uuid,
            topic: $mqttTopic,
            payload: [
                'values' => $finalValues,
                'ingestion_message_id' => $ingestionMessage->id,
                'telemetry_log_id' => $telemetryLog?->id,
                'status' => $status,
                'recorded_at' => $recordedAt->toIso8601String(),
            ],
            host: is_string($host) && $host !== '' ? $host : '127.0.0.1',
            port: is_numeric($port) ? (int) $port : 4223,
        );
    }

    private function resolvedChannelTopic(Device $device, DeviceChannel $channel): string
    {
        $channel->loadMissing('version');

        $profileConfig = $channel->version?->protocol_config;
        $baseTopic = $profileConfig instanceof MqttProtocolConfig ? $profileConfig->getBaseTopic() : 'device';
        $identifier = $device->external_id ?: $device->uuid;

        return trim($baseTopic, '/').'/'.$identifier.'/'.trim($channel->address, '/');
    }
}
