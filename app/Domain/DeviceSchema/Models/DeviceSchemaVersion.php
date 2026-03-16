<?php

declare(strict_types=1);

namespace App\Domain\DeviceSchema\Models;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\ValueObjects\Protocol\MqttProtocolConfig;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use Database\Factories\Domain\DeviceSchema\Models\DeviceSchemaVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class DeviceSchemaVersion extends Model
{
    /** @use HasFactory<DeviceSchemaVersionFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'firmware_template' => 'string',
            'ingestion_config' => 'array',
        ];
    }

    /**
     * @return BelongsTo<DeviceSchema, $this>
     */
    public function schema(): BelongsTo
    {
        return $this->belongsTo(DeviceSchema::class, 'device_schema_id');
    }

    /**
     * @return HasMany<SchemaVersionTopic, $this>
     */
    public function topics(): HasMany
    {
        return $this->hasMany(SchemaVersionTopic::class, 'device_schema_version_id');
    }

    /**
     * @return HasManyThrough<ParameterDefinition, SchemaVersionTopic, $this>
     */
    public function parameters(): HasManyThrough
    {
        return $this->hasManyThrough(
            ParameterDefinition::class,
            SchemaVersionTopic::class,
            'device_schema_version_id',
            'schema_version_topic_id',
        );
    }

    /**
     * @return HasMany<DerivedParameterDefinition, $this>
     */
    public function derivedParameters(): HasMany
    {
        return $this->hasMany(DerivedParameterDefinition::class, 'device_schema_version_id');
    }

    /**
     * @return HasMany<DeviceTelemetryLog, $this>
     */
    public function telemetryLogs(): HasMany
    {
        return $this->hasMany(DeviceTelemetryLog::class, 'device_schema_version_id');
    }

    /**
     * @return HasManyThrough<SchemaVersionTopicLink, SchemaVersionTopic, $this>
     */
    public function topicLinks(): HasManyThrough
    {
        return $this->hasManyThrough(
            SchemaVersionTopicLink::class,
            SchemaVersionTopic::class,
            'device_schema_version_id',
            'from_schema_version_topic_id',
            'id',
            'id',
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function hasFirmwareTemplate(): bool
    {
        $template = $this->getAttribute('firmware_template');

        return is_string($template) && trim($template) !== '';
    }

    public function renderFirmwareForDevice(Device $device): ?string
    {
        $template = $this->getAttribute('firmware_template');

        if (! is_string($template) || trim($template) === '') {
            return null;
        }

        $device->loadMissing('deviceType', 'activeCertificate');
        $this->loadMissing('topics');

        $deviceIdentifier = is_string($device->external_id) && trim($device->external_id) !== ''
            ? $device->external_id
            : $device->uuid;
        $mqttConfig = $device->deviceType?->protocol_config;
        $baseTopic = $mqttConfig?->getBaseTopic() ?? 'device';
        $configuredMqttHost = config('iot.mqtt.host', '127.0.0.1');
        $configuredNatsHost = config('iot.nats.host', '127.0.0.1');
        $configuredMqttPort = config('iot.mqtt.port', 1883);
        $defaultBrokerHost = is_string($configuredMqttHost) && trim($configuredMqttHost) !== ''
            ? trim($configuredMqttHost)
            : '127.0.0.1';
        $fallbackBrokerPort = is_numeric($configuredMqttPort)
            ? (int) $configuredMqttPort
            : 1883;
        $brokerHost = $mqttConfig instanceof MqttProtocolConfig
            ? $mqttConfig->brokerHost
            : $defaultBrokerHost;
        $brokerPort = $mqttConfig instanceof MqttProtocolConfig
            ? $mqttConfig->brokerPort
            : $fallbackBrokerPort;
        $fallbackBrokerHost = $this->resolveFallbackBrokerHost(
            primaryBrokerHost: $brokerHost,
            configuredMqttHost: $configuredMqttHost,
            configuredNatsHost: $configuredNatsHost,
        );
        $configuredMqttTlsEnabled = config('iot.mqtt.tls.enabled', false);
        $fallbackMqttUseTls = is_bool($configuredMqttTlsEnabled)
            ? $configuredMqttTlsEnabled
            : false;
        $mqttUseTls = $mqttConfig instanceof MqttProtocolConfig
            ? $mqttConfig->useTls
            : $fallbackMqttUseTls;
        $mqttUsername = $mqttConfig instanceof MqttProtocolConfig && is_string($mqttConfig->username)
            ? $mqttConfig->username
            : '';
        $mqttPassword = $mqttConfig instanceof MqttProtocolConfig && is_string($mqttConfig->password)
            ? $mqttConfig->password
            : '';
        $mqttSecurityMode = $mqttConfig instanceof MqttProtocolConfig
            ? $mqttConfig->securityMode->value
            : 'username_password';

        $commandTopic = $this->topics->first(fn (SchemaVersionTopic $topic): bool => $topic->isPurposeCommand() || $topic->isSubscribe());
        $stateTopic = $this->topics->first(fn (SchemaVersionTopic $topic): bool => $topic->isPurposeState());
        $telemetryTopic = $this->topics->first(fn (SchemaVersionTopic $topic): bool => $topic->isPurposeTelemetry());
        $ackTopic = $this->topics->first(fn (SchemaVersionTopic $topic): bool => $topic->isPurposeAck());

        $presencePrefixValue = config('iot.presence.subject_prefix', 'devices');
        $presenceSuffixValue = config('iot.presence.subject_suffix', 'presence');
        $presencePrefix = is_string($presencePrefixValue) && trim($presencePrefixValue) !== '' ? trim($presencePrefixValue, '/') : 'devices';
        $presenceSuffix = is_string($presenceSuffixValue) && trim($presenceSuffixValue) !== '' ? trim($presenceSuffixValue, '/') : 'presence';
        $presenceTopic = "{$presencePrefix}/{$deviceIdentifier}/{$presenceSuffix}";

        $caCertificatePath = config('iot.pki.ca_certificate_path', storage_path('app/private/iot-pki/ca.crt'));
        $caCertificatePem = is_string($caCertificatePath) && is_file($caCertificatePath)
            ? (file_get_contents($caCertificatePath) ?: '')
            : '';
        $deviceCertificatePem = is_string($device->activeCertificate?->certificate_pem) ? $device->activeCertificate->certificate_pem : '';
        $devicePrivateKeyPem = $device->activeCertificate?->decryptedPrivateKey() ?? '';

        $replacements = [
            '{{DEVICE_ID}}' => $deviceIdentifier,
            '{{DEVICE_EXTERNAL_ID}}' => $deviceIdentifier,
            '{{DEVICE_UUID}}' => $device->uuid,
            '{{DEVICE_NAME}}' => $device->name,
            '{{MQTT_CLIENT_ID}}' => $deviceIdentifier,
            '{{MQTT_HOST}}' => $brokerHost,
            '{{MQTT_FALLBACK_HOST}}' => $fallbackBrokerHost,
            '{{MQTT_PORT}}' => (string) $brokerPort,
            '{{MQTT_USE_TLS}}' => $mqttUseTls ? 'true' : 'false',
            '{{MQTT_USER}}' => $mqttUsername,
            '{{MQTT_PASS}}' => $mqttPassword,
            '{{MQTT_SECURITY_MODE}}' => $mqttSecurityMode,
            '{{BASE_TOPIC}}' => $baseTopic,
            '{{CONTROL_TOPIC}}' => $commandTopic?->resolvedTopic($device) ?? trim($baseTopic, '/')."/{$deviceIdentifier}/control",
            '{{STATE_TOPIC}}' => $stateTopic?->resolvedTopic($device) ?? trim($baseTopic, '/')."/{$deviceIdentifier}/state",
            '{{TELEMETRY_TOPIC}}' => $telemetryTopic?->resolvedTopic($device) ?? trim($baseTopic, '/')."/{$deviceIdentifier}/telemetry",
            '{{ACK_TOPIC}}' => $ackTopic?->resolvedTopic($device) ?? trim($baseTopic, '/')."/{$deviceIdentifier}/ack",
            '{{PRESENCE_TOPIC}}' => $presenceTopic,
            '{{MQTT_TLS_CA_CERT_PEM}}' => $caCertificatePem,
            '{{MQTT_TLS_CLIENT_CERT_PEM}}' => $deviceCertificatePem,
            '{{MQTT_TLS_CLIENT_KEY_PEM}}' => $devicePrivateKeyPem,
        ];

        return strtr($template, $replacements);
    }

    private function resolveFallbackBrokerHost(
        string $primaryBrokerHost,
        mixed $configuredMqttHost,
        mixed $configuredNatsHost,
    ): string {
        $hostCandidates = [];

        if (is_string($configuredMqttHost) && trim($configuredMqttHost) !== '') {
            $hostCandidates[] = trim($configuredMqttHost);
        }

        if (is_string($configuredNatsHost) && trim($configuredNatsHost) !== '') {
            $hostCandidates[] = trim($configuredNatsHost);
        }

        $normalizedPrimaryHost = strtolower(trim($primaryBrokerHost));

        foreach ($hostCandidates as $hostCandidate) {
            $normalizedHostCandidate = strtolower($hostCandidate);

            if ($normalizedHostCandidate === $normalizedPrimaryHost) {
                continue;
            }

            if ($this->isLikelyInternalHostAlias($hostCandidate)) {
                continue;
            }

            return $hostCandidate;
        }

        return $normalizedPrimaryHost !== ''
            ? $primaryBrokerHost
            : '127.0.0.1';
    }

    private function isLikelyInternalHostAlias(string $host): bool
    {
        $normalizedHost = strtolower(trim($host));

        if ($normalizedHost === '') {
            return true;
        }

        if (filter_var($normalizedHost, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        if (in_array($normalizedHost, ['localhost', 'nats', 'mqtt', 'broker', 'mosquitto'], true)) {
            return true;
        }

        return ! str_contains($normalizedHost, '.');
    }
}
