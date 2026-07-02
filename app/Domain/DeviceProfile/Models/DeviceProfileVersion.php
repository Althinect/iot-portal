<?php

declare(strict_types=1);

namespace App\Domain\DeviceProfile\Models;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\ValueObjects\Protocol\MqttProtocolConfig;
use App\Domain\DeviceManagement\ValueObjects\Protocol\ProtocolConfigInterface;
use App\Domain\DeviceProfile\Casts\ProfileProtocolConfigCast;
use App\Domain\DeviceProfile\Enums\Protocol;
use Database\Factories\Domain\DeviceProfile\Models\DeviceProfileVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * @property int $id
 * @property int $device_profile_id
 * @property int $version
 * @property string $status
 * @property Protocol $protocol
 * @property ProtocolConfigInterface|null $protocol_config
 * @property string|null $firmware_template
 * @property array<string, mixed>|null $ingestion_config
 * @property array<string, mixed>|null $virtual_standard_profile
 * @property string|null $notes
 */
class DeviceProfileVersion extends Model
{
    /** @use HasFactory<DeviceProfileVersionFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUPERSEDED = 'superseded';

    protected $guarded = ['id'];

    protected static function newFactory(): DeviceProfileVersionFactory
    {
        return DeviceProfileVersionFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'protocol' => Protocol::class,
            'protocol_config' => ProfileProtocolConfigCast::class,
            'ingestion_config' => 'array',
            'virtual_standard_profile' => 'array',
            'firmware_template' => 'string',
        ];
    }

    /**
     * @return BelongsTo<DeviceProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(DeviceProfile::class, 'device_profile_id');
    }

    /**
     * @return HasMany<DeviceChannel, $this>
     */
    public function channels(): HasMany
    {
        return $this->hasMany(DeviceChannel::class, 'device_profile_version_id');
    }

    /**
     * @return HasMany<ProfileDerivedParameterDefinition, $this>
     */
    public function derivedParameters(): HasMany
    {
        return $this->hasMany(ProfileDerivedParameterDefinition::class, 'device_profile_version_id');
    }

    /**
     * @return HasMany<Device, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class, 'device_profile_version_id');
    }

    /**
     * @return HasManyThrough<DeviceChannelLink, DeviceChannel, $this>
     */
    public function channelLinks(): HasManyThrough
    {
        return $this->hasManyThrough(
            DeviceChannelLink::class,
            DeviceChannel::class,
            'device_profile_version_id',
            'from_device_channel_id',
            'id',
            'id',
        );
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isSuperseded(): bool
    {
        return $this->status === self::STATUS_SUPERSEDED;
    }

    public function canEditContract(): bool
    {
        return $this->isDraft();
    }

    public function hasFirmwareTemplate(): bool
    {
        $template = $this->getAttribute('firmware_template');

        return is_string($template) && trim($template) !== '';
    }

    public function getProtocolConfig(): ?ProtocolConfigInterface
    {
        return $this->protocol_config;
    }

    public function renderFirmwareForDevice(Device $device): ?string
    {
        $template = $this->getAttribute('firmware_template');

        if (! is_string($template) || trim($template) === '') {
            return null;
        }

        $device->loadMissing('activeCertificate');
        $this->loadMissing('channels');

        $deviceIdentifier = is_string($device->external_id) && trim($device->external_id) !== ''
            ? $device->external_id
            : $device->uuid;
        $mqttConfig = $this->protocol_config;
        $configuredMqttHost = config('iot.mqtt.host', '127.0.0.1');
        $configuredMqttPort = config('iot.mqtt.port', 1883);
        $brokerHost = $mqttConfig instanceof MqttProtocolConfig
            ? $mqttConfig->brokerHost
            : (is_string($configuredMqttHost) ? $configuredMqttHost : '127.0.0.1');
        $brokerPort = $mqttConfig instanceof MqttProtocolConfig
            ? $mqttConfig->brokerPort
            : (is_numeric($configuredMqttPort) ? (int) $configuredMqttPort : 1883);
        $baseTopic = $mqttConfig instanceof MqttProtocolConfig ? $mqttConfig->getBaseTopic() : 'device';

        $commandChannel = $this->channels->first(fn (DeviceChannel $channel): bool => $channel->isPurposeCommand() || $channel->isSubscribe());
        $stateChannel = $this->channels->first(fn (DeviceChannel $channel): bool => $channel->isPurposeState());
        $telemetryChannel = $this->channels->first(fn (DeviceChannel $channel): bool => $channel->isPurposeTelemetry());
        $ackChannel = $this->channels->first(fn (DeviceChannel $channel): bool => $channel->isPurposeAck());

        $replacements = [
            '{{DEVICE_ID}}' => $deviceIdentifier,
            '{{DEVICE_EXTERNAL_ID}}' => $deviceIdentifier,
            '{{DEVICE_UUID}}' => $device->uuid,
            '{{DEVICE_NAME}}' => $device->name,
            '{{MQTT_CLIENT_ID}}' => $deviceIdentifier,
            '{{MQTT_HOST}}' => $brokerHost,
            '{{MQTT_PORT}}' => (string) $brokerPort,
            '{{MQTT_BASE_TOPIC}}' => $baseTopic,
            '{{COMMAND_TOPIC}}' => $commandChannel?->address ?? '',
            '{{STATE_TOPIC}}' => $stateChannel?->address ?? '',
            '{{TELEMETRY_TOPIC}}' => $telemetryChannel?->address ?? '',
            '{{ACK_TOPIC}}' => $ackChannel?->address ?? '',
            '{{DEVICE_CERTIFICATE_PEM}}' => is_string($device->activeCertificate?->certificate_pem) ? $device->activeCertificate->certificate_pem : '',
            '{{DEVICE_PRIVATE_KEY_PEM}}' => $device->activeCertificate?->decryptedPrivateKey() ?? '',
        ];

        return strtr($template, $replacements);
    }
}
