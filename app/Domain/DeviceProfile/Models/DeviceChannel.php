<?php

declare(strict_types=1);

namespace App\Domain\DeviceProfile\Models;

use App\Domain\DeviceProfile\Enums\ChannelDirection;
use App\Domain\DeviceProfile\Enums\ChannelPurpose;
use App\Domain\DeviceProfile\Enums\ChannelTransport;
use Database\Factories\Domain\DeviceProfile\Models\DeviceChannelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $device_profile_version_id
 * @property string $key
 * @property string $label
 * @property ChannelDirection $direction
 * @property ChannelPurpose|null $purpose
 * @property ChannelTransport $transport
 * @property string $address
 * @property string|null $http_method
 * @property string|null $description
 * @property int $qos
 * @property bool $retain
 * @property int $sequence
 * @property array<string, mixed>|null $options
 */
class DeviceChannel extends Model
{
    /** @use HasFactory<DeviceChannelFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected static function newFactory(): DeviceChannelFactory
    {
        return DeviceChannelFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => ChannelDirection::class,
            'purpose' => ChannelPurpose::class,
            'transport' => ChannelTransport::class,
            'qos' => 'integer',
            'retain' => 'boolean',
            'sequence' => 'integer',
            'options' => 'array',
        ];
    }

    /**
     * @return BelongsTo<DeviceProfileVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(DeviceProfileVersion::class, 'device_profile_version_id');
    }

    /**
     * @return HasMany<ProfileParameterDefinition, $this>
     */
    public function parameters(): HasMany
    {
        return $this->hasMany(ProfileParameterDefinition::class, 'device_channel_id');
    }

    /**
     * @return HasMany<DeviceChannelLink, $this>
     */
    public function outgoingLinks(): HasMany
    {
        return $this->hasMany(DeviceChannelLink::class, 'from_device_channel_id');
    }

    /**
     * @return HasMany<DeviceChannelLink, $this>
     */
    public function incomingLinks(): HasMany
    {
        return $this->hasMany(DeviceChannelLink::class, 'to_device_channel_id');
    }

    /**
     * @return BelongsToMany<DeviceChannel, $this>
     */
    public function linkedFeedbackChannels(): BelongsToMany
    {
        return $this->belongsToMany(
            DeviceChannel::class,
            'device_channel_links',
            'from_device_channel_id',
            'to_device_channel_id',
        )->withPivot('link_type')->withTimestamps();
    }

    public function isPublish(): bool
    {
        return $this->direction === ChannelDirection::Publish;
    }

    public function isSubscribe(): bool
    {
        return $this->direction === ChannelDirection::Subscribe;
    }

    public function resolvedPurpose(): ChannelPurpose
    {
        $purpose = $this->getAttribute('purpose');

        if ($purpose instanceof ChannelPurpose) {
            return $purpose;
        }

        $address = strtolower((string) $this->address);

        return match (true) {
            $this->isSubscribe() => ChannelPurpose::Command,
            str_contains($address, 'ack') => ChannelPurpose::Ack,
            ($this->retain ?? false) || in_array($address, ['state', 'status'], true) => ChannelPurpose::State,
            default => ChannelPurpose::Telemetry,
        };
    }

    public function isPurposeCommand(): bool
    {
        return $this->resolvedPurpose() === ChannelPurpose::Command;
    }

    public function isPurposeState(): bool
    {
        return $this->resolvedPurpose() === ChannelPurpose::State;
    }

    public function isPurposeTelemetry(): bool
    {
        return $this->resolvedPurpose() === ChannelPurpose::Telemetry;
    }

    public function isPurposeEvent(): bool
    {
        return $this->resolvedPurpose() === ChannelPurpose::Event;
    }

    public function isPurposeAck(): bool
    {
        return $this->resolvedPurpose() === ChannelPurpose::Ack;
    }
}
