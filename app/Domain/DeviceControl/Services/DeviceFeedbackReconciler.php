<?php

declare(strict_types=1);

namespace App\Domain\DeviceControl\Services;

use App\Domain\DeviceControl\Enums\CommandStatus;
use App\Domain\DeviceControl\Models\DeviceCommandLog;
use App\Domain\DeviceControl\Models\DeviceDesiredChannelState;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Publishing\Nats\NatsDeviceStateStore;
use App\Domain\DeviceManagement\Services\DevicePresenceService;
use App\Domain\DeviceProfile\Enums\ChannelLinkType;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Services\ProfileChannelResolver;
use App\Events\CommandCompleted;
use App\Events\DeviceStateReceived;
use Illuminate\Log\LogManager;
use Psr\Log\LoggerInterface;

class DeviceFeedbackReconciler
{
    public function __construct(
        private readonly NatsDeviceStateStore $stateStore,
        private readonly DevicePresenceService $presenceService,
        private readonly LogManager $logManager,
        private readonly ProfileChannelResolver $channelResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     device_uuid: string,
     *     device_external_id: string|null,
     *     device_channel_id: int,
     *     topic: string,
     *     purpose: string,
     *     command_log_id: int|null
     * }|null
     */
    public function reconcileInboundMessage(
        string $mqttTopic,
        array $payload,
        string $host = '127.0.0.1',
        int $port = 4223,
    ): ?array {
        if ($this->isInternalBridgeTopic($mqttTopic)) {
            return null;
        }

        $this->log()->debug('Reconciling inbound message', [
            'mqtt_topic' => $mqttTopic,
            'payload' => $payload,
        ]);

        $resolved = $this->channelResolver->resolve($mqttTopic);

        if ($resolved === null) {
            $this->log()->debug('No registry match for topic — ignoring', [
                'mqtt_topic' => $mqttTopic,
            ]);

            return null;
        }

        $device = $resolved['device'];
        $channel = DeviceChannel::query()->find($resolved['channel']->id);

        if (! $channel instanceof DeviceChannel) {
            return null;
        }

        $this->log()->debug('Inbound message matched', [
            'mqtt_topic' => $mqttTopic,
            'device_uuid' => $device->uuid,
            'device_external_id' => $device->external_id,
            'device_channel_id' => $channel->id,
            'channel_key' => $channel->key,
            'purpose' => $channel->resolvedPurpose()->value,
        ]);

        $this->presenceService->markOnline($device);

        $this->stateStore->store($device->uuid, $mqttTopic, $payload, $host, $port);

        $matchedCommandLog = $this->matchCommandLog($device, $channel, $payload);

        if ($matchedCommandLog instanceof DeviceCommandLog) {
            $this->log()->debug('Matched command log for feedback', [
                'command_log_id' => $matchedCommandLog->id,
                'correlation_id' => $matchedCommandLog->correlation_id,
                'current_status' => $matchedCommandLog->getRawOriginal('status'),
            ]);

            $this->applyFeedbackToCommandLog($matchedCommandLog, $channel, $payload);
        } else {
            $this->log()->debug('No pending command log matched', [
                'device_uuid' => $device->uuid,
                'mqtt_topic' => $mqttTopic,
            ]);
        }

        $this->log()->debug('Broadcasting DeviceStateReceived', [
            'device_uuid' => $device->uuid,
            'mqtt_topic' => $mqttTopic,
            'command_log_id' => $matchedCommandLog?->id,
        ]);

        event(new DeviceStateReceived(
            topic: $mqttTopic,
            deviceUuid: $device->uuid,
            deviceExternalId: $device->external_id,
            payload: $payload,
            commandLogId: $matchedCommandLog?->id,
        ));

        return [
            'device_uuid' => $device->uuid,
            'device_external_id' => $device->external_id,
            'device_channel_id' => (int) $channel->id,
            'topic' => $mqttTopic,
            'purpose' => $channel->resolvedPurpose()->value,
            'command_log_id' => $matchedCommandLog?->id,
        ];
    }

    public function refreshRegistry(): void
    {
        $this->channelResolver->refreshRegistry();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function matchCommandLog(Device $device, DeviceChannel $incomingChannel, array $payload): ?DeviceCommandLog
    {
        $correlationId = $this->extractCorrelationId($payload);

        if ($correlationId !== null) {
            $matched = DeviceCommandLog::query()
                ->where('device_id', $device->id)
                ->where('correlation_id', $correlationId)
                ->whereIn('status', [
                    CommandStatus::Pending->value,
                    CommandStatus::Sent->value,
                    CommandStatus::Acknowledged->value,
                ])
                ->latest('id')
                ->first();

            if ($matched !== null) {
                return $matched;
            }
        }

        $candidateChannelIds = $this->resolveCandidateCommandChannelIds($device, $incomingChannel);

        $candidates = DeviceCommandLog::query()
            ->when(
                $candidateChannelIds !== [],
                fn ($query) => $query->whereIn('device_channel_id', $candidateChannelIds)
            )
            ->where('device_id', $device->id)
            ->whereIn('status', [
                CommandStatus::Pending->value,
                CommandStatus::Sent->value,
                CommandStatus::Acknowledged->value,
            ])
            ->where('created_at', '>=', now()->subMinutes(10))
            ->latest('id')
            ->limit(25)
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        $feedbackComparable = $this->flattenPayload($this->withoutMeta($payload));
        $bestMatch = null;
        $bestScore = -1;

        foreach ($candidates as $candidate) {
            $commandComparable = $this->flattenPayload(
                $this->withoutMeta($this->normalizePayload($candidate->command_payload))
            );
            $score = $this->payloadOverlapScore($commandComparable, $feedbackComparable);

            if ($score > $bestScore) {
                $bestMatch = $candidate;
                $bestScore = $score;
            }
        }

        if ($bestMatch !== null && $bestScore > 0) {
            return $bestMatch;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyFeedbackToCommandLog(DeviceCommandLog $commandLog, DeviceChannel $incomingChannel, array $payload): void
    {
        $now = now();

        $updates = [
            'response_payload' => $payload,
            'response_device_channel_id' => $incomingChannel->id,
        ];

        if ($incomingChannel->isPurposeAck()) {
            $updates['status'] = CommandStatus::Acknowledged;
            $updates['acknowledged_at'] = $commandLog->acknowledged_at ?? $now;

            if ($this->shouldCompleteOnAck($commandLog)) {
                $updates['status'] = CommandStatus::Completed;
                $updates['completed_at'] = $now;
            }
        } else {
            $updates['status'] = CommandStatus::Completed;
            $updates['acknowledged_at'] = $commandLog->acknowledged_at ?? $now;
            $updates['completed_at'] = $now;
        }

        $newStatus = $updates['status']->value;

        $this->log()->debug('Updating command log from feedback', [
            'command_log_id' => $commandLog->id,
            'new_status' => $newStatus,
            'incoming_channel_id' => $incomingChannel->id,
            'incoming_purpose' => $incomingChannel->resolvedPurpose()->value,
        ]);

        $commandLog->update($updates);
        $commandLog->refresh();
        $commandLog->loadMissing('device');

        $isCompleted = $this->isCompletedStatus($commandLog->status);

        if ($isCompleted) {
            $this->log()->debug('Command completed — broadcasting CommandCompleted', [
                'command_log_id' => $commandLog->id,
                'correlation_id' => $commandLog->correlation_id,
                'device_uuid' => $commandLog->device?->uuid,
            ]);

            event(new CommandCompleted($commandLog));

            $desiredQuery = DeviceDesiredChannelState::query()
                ->where('device_id', $commandLog->device_id)
                ->where('device_channel_id', $commandLog->device_channel_id);

            if (is_string($commandLog->correlation_id) && $commandLog->correlation_id !== '') {
                $desiredQuery->where('correlation_id', $commandLog->correlation_id);
            }

            $desiredQuery->update([
                'reconciled_at' => $now,
            ]);
        }
    }

    /**
     * @return array<int, int>
     */
    private function resolveCandidateCommandChannelIds(Device $device, DeviceChannel $incomingChannel): array
    {
        $linkType = match (true) {
            $incomingChannel->isPurposeState() => ChannelLinkType::StateFeedback->value,
            $incomingChannel->isPurposeAck() => ChannelLinkType::AckFeedback->value,
            default => null,
        };

        $linkedChannelIds = $incomingChannel->incomingLinks()
            ->when($linkType !== null, fn ($query) => $query->where('link_type', $linkType))
            ->pluck('from_device_channel_id')
            ->all();

        $normalizedLinkedChannelIds = [];

        foreach ($linkedChannelIds as $linkedChannelId) {
            if (! is_numeric($linkedChannelId)) {
                continue;
            }

            $normalizedLinkedChannelIds[] = (int) $linkedChannelId;
        }

        $normalizedLinkedChannelIds = array_values(array_unique($normalizedLinkedChannelIds));

        if ($normalizedLinkedChannelIds !== []) {
            return $normalizedLinkedChannelIds;
        }

        $device->loadMissing('profileVersion.channels');

        $channels = $device->profileVersion?->channels;

        if ($channels === null) {
            return [];
        }

        $channelIds = [];

        foreach ($channels as $channel) {
            if (! $channel->isPurposeCommand()) {
                continue;
            }

            $channelIds[] = (int) $channel->id;
        }

        return array_values(array_unique($channelIds));
    }

    private function shouldCompleteOnAck(DeviceCommandLog $commandLog): bool
    {
        $commandLog->loadMissing('channel.outgoingLinks');
        $commandChannel = $commandLog->channel;

        if (! $commandChannel instanceof DeviceChannel) {
            return true;
        }

        return $commandChannel->outgoingLinks()
            ->where('link_type', ChannelLinkType::StateFeedback->value)
            ->doesntExist();
    }

    /**
     * @param  array<int|string, mixed>  $payload
     */
    private function extractCorrelationId(array $payload): ?string
    {
        $correlationId = data_get($payload, '_meta.command_id');

        if (! is_string($correlationId) || trim($correlationId) === '') {
            return null;
        }

        return trim($correlationId);
    }

    /**
     * @param  array<int|string, mixed>  $payload
     * @return array<int|string, mixed>
     */
    private function withoutMeta(array $payload): array
    {
        unset($payload['_meta']);

        return $payload;
    }

    /**
     * @param  array<int|string, mixed>  $payload
     * @return array<string, string>
     */
    private function flattenPayload(array $payload, string $prefix = ''): array
    {
        $flattened = [];

        foreach ($payload as $key => $value) {
            $fullKey = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value)) {
                $flattened += $this->flattenPayload($value, $fullKey);

                continue;
            }

            if (is_scalar($value) || $value === null) {
                $flattened[$fullKey] = (string) $value;
            }
        }

        return $flattened;
    }

    /**
     * @param  array<string, string>  $left
     * @param  array<string, string>  $right
     */
    private function payloadOverlapScore(array $left, array $right): int
    {
        $score = 0;

        foreach ($left as $key => $value) {
            if (array_key_exists($key, $right) && $right[$key] === $value) {
                $score++;
            }
        }

        return $score;
    }

    private function isCompletedStatus(mixed $status): bool
    {
        if ($status instanceof CommandStatus) {
            return $status === CommandStatus::Completed;
        }

        return $status === CommandStatus::Completed->value;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizePayload(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    private function isInternalBridgeTopic(string $mqttTopic): bool
    {
        return str_starts_with($mqttTopic, '$MQTT/')
            || str_starts_with($mqttTopic, '$JS/')
            || str_starts_with($mqttTopic, '_REQS/')
            || str_starts_with($mqttTopic, '$KV/')
            || str_starts_with($mqttTopic, '$SYS/');
    }

    private function log(): LoggerInterface
    {
        return $this->logManager->channel('device_control');
    }
}
