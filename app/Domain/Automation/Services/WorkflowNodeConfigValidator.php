<?php

declare(strict_types=1);

namespace App\Domain\Automation\Services;

use App\Domain\Alerts\Models\NotificationProfile;
use App\Domain\Automation\Data\WorkflowGraph;
use App\Domain\Automation\Models\AutomationWorkflow;
use App\Domain\DeviceControl\Services\CommandPayloadResolver;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use Illuminate\Support\Arr;
use RuntimeException;

class WorkflowNodeConfigValidator
{
    public function __construct(
        private readonly CommandPayloadResolver $commandPayloadResolver,
        private readonly WorkflowQueryExecutor $workflowQueryExecutor,
    ) {}

    public function validate(AutomationWorkflow $workflow, WorkflowGraph $graph): void
    {
        $organizationId = (int) $workflow->organization_id;

        foreach ($graph->nodes as $node) {
            $nodeType = Arr::get($node, 'type');
            $nodeId = Arr::get($node, 'id');
            $resolvedNodeId = is_string($nodeId) && $nodeId !== '' ? $nodeId : 'unknown-node';

            if (! is_string($nodeType) || $nodeType === '') {
                throw new RuntimeException("Node [{$resolvedNodeId}] has an invalid type.");
            }

            if ($nodeType === 'telemetry-trigger') {
                $this->validateTelemetryTriggerNode($organizationId, $resolvedNodeId, $node);

                continue;
            }

            if ($nodeType === 'condition') {
                $this->validateConditionNode($resolvedNodeId, $node);

                continue;
            }

            if ($nodeType === 'command') {
                $this->validateCommandNode($organizationId, $resolvedNodeId, $node);

                continue;
            }

            if ($nodeType === 'query') {
                $this->validateQueryNode($organizationId, $resolvedNodeId, $node);

                continue;
            }

            if ($nodeType === 'alert') {
                $this->validateAlertNode($organizationId, $resolvedNodeId, $node);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function validateTelemetryTriggerNode(int $organizationId, string $nodeId, array $node): void
    {
        $config = Arr::get($node, 'data.config');
        if (! is_array($config)) {
            throw new RuntimeException("Telemetry trigger node [{$nodeId}] requires a configuration.");
        }

        $mode = Arr::get($config, 'mode');
        if ($mode !== 'event') {
            throw new RuntimeException("Telemetry trigger node [{$nodeId}] must use event mode.");
        }

        $source = Arr::get($config, 'source');
        if (! is_array($source)) {
            throw new RuntimeException("Telemetry trigger node [{$nodeId}] must define a source.");
        }

        $deviceId = $this->resolvePositiveInt($source['device_id'] ?? null);
        $channelId = $this->resolvePositiveInt($source['device_channel_id'] ?? $source['channel_id'] ?? null);
        $parameterKey = $this->resolveNonEmptyString($source['parameter_key'] ?? null);

        if ($deviceId === null || $channelId === null || $parameterKey === null) {
            throw new RuntimeException("Telemetry trigger node [{$nodeId}] is missing source device, channel, or parameter.");
        }

        $device = Device::query()
            ->where('organization_id', $organizationId)
            ->find($deviceId);

        if (! $device instanceof Device) {
            throw new RuntimeException("Telemetry trigger node [{$nodeId}] references an invalid source device.");
        }

        $channel = DeviceChannel::query()
            ->whereKey($channelId)
            ->where('device_profile_version_id', $device->device_profile_version_id)
            ->first();

        if (! $channel instanceof DeviceChannel || ! $channel->isPublish()) {
            throw new RuntimeException("Telemetry trigger node [{$nodeId}] references an invalid publish channel.");
        }

        $parameter = ProfileParameterDefinition::query()
            ->where('device_channel_id', $channel->id)
            ->where('key', $parameterKey)
            ->where('is_active', true)
            ->first();

        if (! $parameter instanceof ProfileParameterDefinition) {
            throw new RuntimeException("Telemetry trigger node [{$nodeId}] references an invalid telemetry parameter.");
        }
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function validateConditionNode(string $nodeId, array $node): void
    {
        $config = Arr::get($node, 'data.config');
        if (! is_array($config)) {
            throw new RuntimeException("Condition node [{$nodeId}] requires a configuration.");
        }

        $mode = Arr::get($config, 'mode');
        if (! in_array($mode, ['guided', 'json_logic'], true)) {
            throw new RuntimeException("Condition node [{$nodeId}] has an invalid mode.");
        }

        $jsonLogic = Arr::get($config, 'json_logic');
        if (! is_array($jsonLogic) || $jsonLogic === [] || ! Arr::isAssoc($jsonLogic) || count($jsonLogic) !== 1) {
            throw new RuntimeException("Condition node [{$nodeId}] must define valid JSON logic.");
        }

        if ($mode !== 'guided') {
            return;
        }

        $guided = Arr::get($config, 'guided');
        if (! is_array($guided)) {
            throw new RuntimeException("Condition node [{$nodeId}] guided mode requires guided settings.");
        }

        try {
            /** @var array<string, mixed> $guided */
            app(GuidedConditionService::class)->normalize($guided);
        } catch (\InvalidArgumentException $exception) {
            throw new RuntimeException("Condition node [{$nodeId}] {$exception->getMessage()}");
        }
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function validateCommandNode(int $organizationId, string $nodeId, array $node): void
    {
        $config = Arr::get($node, 'data.config');
        if (! is_array($config)) {
            throw new RuntimeException("Command node [{$nodeId}] requires a configuration.");
        }

        $payloadMode = Arr::get($config, 'payload_mode');
        if (! in_array($payloadMode, ['schema_form', 'profile_form'], true)) {
            throw new RuntimeException("Command node [{$nodeId}] must use profile_form payload mode.");
        }

        $target = Arr::get($config, 'target');
        if (! is_array($target)) {
            throw new RuntimeException("Command node [{$nodeId}] must define a target.");
        }

        $targetDeviceId = $this->resolvePositiveInt($target['device_id'] ?? null);
        $targetChannelId = $this->resolvePositiveInt($target['device_channel_id'] ?? $target['channel_id'] ?? null);

        if ($targetDeviceId === null || $targetChannelId === null) {
            throw new RuntimeException("Command node [{$nodeId}] is missing target device or channel.");
        }

        $payload = Arr::get($config, 'payload');
        if (! is_array($payload)) {
            throw new RuntimeException("Command node [{$nodeId}] must define a payload object.");
        }

        $targetDevice = Device::query()
            ->where('organization_id', $organizationId)
            ->find($targetDeviceId);

        if (! $targetDevice instanceof Device) {
            throw new RuntimeException("Command node [{$nodeId}] references an invalid target device.");
        }

        $channel = DeviceChannel::query()
            ->whereKey($targetChannelId)
            ->where('device_profile_version_id', $targetDevice->device_profile_version_id)
            ->first();

        if (! $channel instanceof DeviceChannel || ! $channel->isSubscribe()) {
            throw new RuntimeException("Command node [{$nodeId}] references an invalid command channel.");
        }

        $errors = $this->commandPayloadResolver->validatePayload($channel, $this->normalizeStringKeyArray($payload));
        if ($errors !== []) {
            $failedKeys = implode(', ', array_keys($errors));

            throw new RuntimeException("Command node [{$nodeId}] has invalid payload values: {$failedKeys}.");
        }
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function validateQueryNode(int $organizationId, string $nodeId, array $node): void
    {
        $config = Arr::get($node, 'data.config');
        if (! is_array($config)) {
            throw new RuntimeException("Query node [{$nodeId}] requires a configuration.");
        }

        try {
            $this->workflowQueryExecutor->validateConfig($organizationId, $this->normalizeStringKeyArray($config));
        } catch (RuntimeException $exception) {
            throw new RuntimeException("Query node [{$nodeId}] {$exception->getMessage()}");
        }
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function validateAlertNode(int $organizationId, string $nodeId, array $node): void
    {
        $config = Arr::get($node, 'data.config');

        if (! is_array($config) || $config === []) {
            return;
        }

        $notificationProfileId = $this->resolvePositiveInt($config['notification_profile_id'] ?? null);

        if ($notificationProfileId !== null) {
            $profileExists = NotificationProfile::query()
                ->whereKey($notificationProfileId)
                ->where('organization_id', $organizationId)
                ->exists();

            if (! $profileExists) {
                throw new RuntimeException("Alert node [{$nodeId}] must reference a valid notification profile.");
            }

            $cooldown = Arr::get($config, 'cooldown');
            if (! is_array($cooldown)) {
                throw new RuntimeException("Alert node [{$nodeId}] cooldown configuration is required.");
            }

            $cooldownValue = $this->resolvePositiveInt($cooldown['value'] ?? null);
            $cooldownUnit = $cooldown['unit'] ?? null;

            if ($cooldownValue === null || ! is_string($cooldownUnit) || ! in_array($cooldownUnit, ['minute', 'hour', 'day'], true)) {
                throw new RuntimeException("Alert node [{$nodeId}] cooldown must include positive value and valid unit.");
            }

            return;
        }

        $alertRuntimeKeys = ['channel', 'recipients', 'subject', 'body', 'cooldown'];
        $hasRuntimeConfig = false;

        foreach ($alertRuntimeKeys as $alertRuntimeKey) {
            if (array_key_exists($alertRuntimeKey, $config)) {
                $hasRuntimeConfig = true;

                break;
            }
        }

        if (! $hasRuntimeConfig) {
            return;
        }

        $channel = Arr::get($config, 'channel');
        if (! is_string($channel) || ! in_array($channel, ['email', 'sms'], true)) {
            throw new RuntimeException("Alert node [{$nodeId}] channel must be email or sms.");
        }

        $recipients = Arr::get($config, 'recipients');
        if (! is_array($recipients) || $recipients === []) {
            throw new RuntimeException("Alert node [{$nodeId}] requires at least one recipient.");
        }

        $hasValidRecipient = false;

        foreach ($recipients as $recipient) {
            if (! is_string($recipient) || trim($recipient) === '') {
                continue;
            }

            $resolvedRecipient = trim($recipient);

            if ($channel === 'email' && filter_var($resolvedRecipient, FILTER_VALIDATE_EMAIL) === false) {
                throw new RuntimeException("Alert node [{$nodeId}] recipients must be valid email addresses.");
            }

            if ($channel === 'sms' && ! preg_match('/^94[0-9]{9}$/', $resolvedRecipient)) {
                throw new RuntimeException("Alert node [{$nodeId}] recipients must be valid phone numbers in 94XXXXXXXXX format.");
            }

            $hasValidRecipient = true;
        }

        if (! $hasValidRecipient) {
            throw new RuntimeException("Alert node [{$nodeId}] requires at least one recipient.");
        }

        $subject = Arr::get($config, 'subject');
        $body = Arr::get($config, 'body');

        if (! is_string($subject) || trim($subject) === '') {
            throw new RuntimeException("Alert node [{$nodeId}] subject is required.");
        }

        if (! is_string($body) || trim($body) === '') {
            throw new RuntimeException("Alert node [{$nodeId}] body is required.");
        }

        $cooldown = Arr::get($config, 'cooldown');
        if (! is_array($cooldown)) {
            throw new RuntimeException("Alert node [{$nodeId}] cooldown configuration is required.");
        }

        $cooldownValue = $this->resolvePositiveInt($cooldown['value'] ?? null);
        $cooldownUnit = $cooldown['unit'] ?? null;

        if ($cooldownValue === null || ! is_string($cooldownUnit) || ! in_array($cooldownUnit, ['minute', 'hour', 'day'], true)) {
            throw new RuntimeException("Alert node [{$nodeId}] cooldown must include positive value and valid unit.");
        }
    }

    private function resolvePositiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value) || ! ctype_digit($value)) {
            return null;
        }

        $resolved = (int) $value;

        return $resolved > 0 ? $resolved : null;
    }

    private function resolveNonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $resolved = trim($value);

        return $resolved !== '' ? $resolved : null;
    }

    /**
     * @param  array<mixed, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeStringKeyArray(array $payload): array
    {
        $resolved = [];

        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $resolved[$key] = $value;
            }
        }

        return $resolved;
    }
}
