<?php

declare(strict_types=1);

namespace App\Domain\DeviceManagement\Models;

use App\Domain\DataIngestion\Models\DeviceSignalBinding;
use App\Domain\DeviceControl\Models\DeviceCommandLog;
use App\Domain\DeviceControl\Models\DeviceDesiredChannelState;
use App\Domain\DeviceControl\Models\DeviceDesiredState;
use App\Domain\DeviceManagement\Services\DevicePresencePolicy;
use App\Domain\DeviceManagement\Services\VirtualStandardProfileRegistry;
use App\Domain\DeviceManagement\ValueObjects\VirtualStandards\VirtualStandardProfile;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Models\DeviceTwin;
use App\Domain\Shared\Models\Entity;
use App\Domain\Shared\Models\Organization;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use Database\Factories\Domain\DeviceManagement\Models\DeviceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = ['id'];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $device): void {
            if (! $device->uuid) {
                $device->uuid = (string) Str::uuid();
            }
        });

        static::saving(function (self $device): void {
            if ($device->connection_state === 'offline' || $device->last_seen_at === null) {
                $device->setAttribute('offline_deadline_at', null);

                return;
            }

            $lastSeenAt = $device->lastSeenAt();

            if ($lastSeenAt === null) {
                $device->setAttribute('offline_deadline_at', null);

                return;
            }

            $device->setAttribute('offline_deadline_at', app(DevicePresencePolicy::class)->offlineDeadlineFor($device, $lastSeenAt));
        });
    }

    protected static function newFactory(): DeviceFactory
    {
        return DeviceFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_virtual' => 'bool',
            'is_active' => 'bool',
            'last_seen_at' => 'datetime',
            'offline_deadline_at' => 'datetime',
            'metadata' => 'array',
            'ingestion_overrides' => 'array',
            'presence_timeout_seconds' => 'int',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<Entity, $this>
     */
    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    /**
     * @return BelongsTo<DeviceProfileVersion, $this>
     */
    public function profileVersion(): BelongsTo
    {
        return $this->belongsTo(DeviceProfileVersion::class, 'device_profile_version_id');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parentDevice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_device_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function childDevices(): HasMany
    {
        return $this->hasMany(self::class, 'parent_device_id');
    }

    /**
     * @return HasMany<VirtualDeviceLink, $this>
     */
    public function virtualDeviceLinks(): HasMany
    {
        return $this->hasMany(VirtualDeviceLink::class, 'virtual_device_id')
            ->orderBy('purpose')
            ->orderBy('sequence');
    }

    /**
     * @return HasMany<VirtualDeviceLink, $this>
     */
    public function sourceDeviceLinks(): HasMany
    {
        return $this->hasMany(VirtualDeviceLink::class, 'source_device_id')
            ->orderBy('purpose')
            ->orderBy('sequence');
    }

    /**
     * @return HasMany<DeviceTelemetryLog, $this>
     */
    public function telemetryLogs(): HasMany
    {
        return $this->hasMany(DeviceTelemetryLog::class);
    }

    /**
     * @return HasMany<DeviceSignalBinding, $this>
     */
    public function signalBindings(): HasMany
    {
        return $this->hasMany(DeviceSignalBinding::class);
    }

    /**
     * @return HasOne<DeviceDesiredState, $this>
     */
    public function desiredState(): HasOne
    {
        return $this->hasOne(DeviceDesiredState::class);
    }

    /**
     * @return HasMany<DeviceCommandLog, $this>
     */
    public function commandLogs(): HasMany
    {
        return $this->hasMany(DeviceCommandLog::class);
    }

    /**
     * @return HasMany<DeviceDesiredChannelState, $this>
     */
    public function desiredChannelStates(): HasMany
    {
        return $this->hasMany(DeviceDesiredChannelState::class);
    }

    /**
     * @return HasMany<DeviceCertificate, $this>
     */
    public function certificates(): HasMany
    {
        return $this->hasMany(DeviceCertificate::class);
    }

    /**
     * @return HasOne<DeviceCertificate, $this>
     */
    public function activeCertificate(): HasOne
    {
        return $this->hasOne(DeviceCertificate::class)->ofMany(
            ['issued_at' => 'max'],
            function ($query): void {
                $query
                    ->whereNull('revoked_at')
                    ->where('not_after', '>', now());
            }
        );
    }

    /**
     * @return HasOne<TemporaryDevice, $this>
     */
    public function temporaryDevice(): HasOne
    {
        return $this->hasOne(TemporaryDevice::class);
    }

    /**
     * @return HasOne<DeviceTwin, $this>
     */
    public function twin(): HasOne
    {
        return $this->hasOne(DeviceTwin::class);
    }

    public function isHub(): bool
    {
        return $this->childDevices()->exists();
    }

    public function isVirtual(): bool
    {
        return (bool) $this->getAttribute('is_virtual');
    }

    public function isChildDevice(): bool
    {
        return $this->parent_device_id !== null;
    }

    public function virtualStandardProfile(): ?VirtualStandardProfile
    {
        return app(VirtualStandardProfileRegistry::class)->forDevice($this);
    }

    public function isVirtualStandard(): bool
    {
        return $this->virtualStandardProfile() !== null;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVirtual(Builder $query): Builder
    {
        return $query->where('is_virtual', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePhysical(Builder $query): Builder
    {
        return $query->where('is_virtual', false);
    }

    public function canBeControlled(): bool
    {
        if ($this->getAttribute('device_profile_version_id') === null) {
            return false;
        }

        $this->loadMissing('profileVersion.channels');

        return $this->profileVersion?->channels
            ?->contains(fn ($channel): bool => $channel->isPurposeCommand() || $channel->isSubscribe())
            ?? false;
    }

    public function canBeSimulated(): bool
    {
        if ($this->getAttribute('device_profile_version_id') === null) {
            return false;
        }

        $this->loadMissing('profileVersion.channels');

        return $this->profileVersion?->channels
            ?->contains(fn ($channel): bool => $channel->isPublish())
            ?? false;
    }

    public function effectiveConnectionState(?Carbon $now = null): string
    {
        return app(DevicePresencePolicy::class)->effectiveStateFor($this, $now ?? now());
    }

    public function presenceTimeoutSeconds(): int
    {
        return app(DevicePresencePolicy::class)->timeoutFor($this);
    }

    public function resolvedOfflineDeadlineAt(): ?Carbon
    {
        $storedOfflineDeadlineAt = $this->storedOfflineDeadlineAt();

        if ($storedOfflineDeadlineAt !== null) {
            return $storedOfflineDeadlineAt;
        }

        $lastSeenAt = $this->lastSeenAt();

        if ($lastSeenAt === null) {
            return null;
        }

        return app(DevicePresencePolicy::class)->offlineDeadlineFor($this, $lastSeenAt);
    }

    public function presenceStatusTooltip(): string
    {
        $effectiveState = Str::headline($this->effectiveConnectionState());
        $timeoutSeconds = $this->presenceTimeoutSeconds();
        $lastSeenAt = $this->lastSeenAt();

        if ($lastSeenAt === null) {
            return "Effective status: {$effectiveState}. Waiting for the first device signal. Timeout: {$timeoutSeconds} seconds.";
        }

        $deadline = $this->resolvedOfflineDeadlineAt();
        $deadlineDescription = $deadline?->toDateTimeString() ?? 'Pending first signal';

        return "Effective status: {$effectiveState}. Timeout: {$timeoutSeconds} seconds. Last seen: {$lastSeenAt->toDateTimeString()}. Offline deadline: {$deadlineDescription}.";
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWhereEffectiveConnectionState(Builder $query, string $state, ?Carbon $now = null): Builder
    {
        $now ??= now();
        $fallbackTimeoutSeconds = config('iot.presence.heartbeat_timeout_seconds', 300);
        $resolvedTimeoutSeconds = is_numeric($fallbackTimeoutSeconds) && (int) $fallbackTimeoutSeconds > 0
            ? (int) $fallbackTimeoutSeconds
            : 300;
        $fallbackCutoff = $now->copy()->subSeconds($resolvedTimeoutSeconds);
        $notExplicitlyOffline = static function (Builder $query): void {
            $query
                ->where('connection_state', '!=', 'offline')
                ->orWhereNull('connection_state');
        };

        return match ($state) {
            'online' => $query
                ->where($notExplicitlyOffline)
                ->whereNotNull('last_seen_at')
                ->where(function (Builder $query) use ($fallbackCutoff, $now): void {
                    $query
                        ->where('offline_deadline_at', '>', $now)
                        ->orWhere(function (Builder $query) use ($fallbackCutoff): void {
                            $query
                                ->whereNull('offline_deadline_at')
                                ->where('last_seen_at', '>', $fallbackCutoff);
                        });
                }),
            'offline' => $query->where(function (Builder $query) use ($notExplicitlyOffline, $fallbackCutoff, $now): void {
                $query
                    ->where('connection_state', 'offline')
                    ->orWhere(function (Builder $query) use ($notExplicitlyOffline, $fallbackCutoff, $now): void {
                        $query
                            ->where($notExplicitlyOffline)
                            ->whereNotNull('last_seen_at')
                            ->where(function (Builder $query) use ($fallbackCutoff, $now): void {
                                $query
                                    ->where('offline_deadline_at', '<=', $now)
                                    ->orWhere(function (Builder $query) use ($fallbackCutoff): void {
                                        $query
                                            ->whereNull('offline_deadline_at')
                                            ->where('last_seen_at', '<=', $fallbackCutoff);
                                    });
                            });
                    });
            }),
            'unknown' => $query
                ->where($notExplicitlyOffline)
                ->whereNull('last_seen_at'),
            default => $query,
        };
    }

    public function lastSeenAt(): ?Carbon
    {
        return Carbon::make($this->getAttributeValue('last_seen_at'));
    }

    public function storedOfflineDeadlineAt(): ?Carbon
    {
        return Carbon::make($this->getAttributeValue('offline_deadline_at'));
    }
}
