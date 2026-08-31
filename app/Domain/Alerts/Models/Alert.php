<?php

declare(strict_types=1);

namespace App\Domain\Alerts\Models;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use Database\Factories\Domain\Alerts\Models\AlertFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Alert extends Model
{
    /** @use HasFactory<AlertFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'alerted_at' => 'datetime',
            'normalized_at' => 'datetime',
            'alert_notification_sent_at' => 'datetime',
            'normalized_notification_sent_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    protected static function newFactory(): AlertFactory
    {
        return AlertFactory::new();
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<ThresholdPolicy, $this> */
    public function thresholdPolicy(): BelongsTo
    {
        return $this->belongsTo(ThresholdPolicy::class, 'threshold_policy_id');
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @return BelongsTo<DeviceChannel, $this> */
    public function deviceChannel(): BelongsTo
    {
        return $this->belongsTo(DeviceChannel::class);
    }

    /** @return BelongsTo<DeviceTelemetryLog, $this> */
    public function alertedTelemetryLog(): BelongsTo
    {
        return $this->belongsTo(DeviceTelemetryLog::class, 'alerted_telemetry_log_id');
    }

    /** @return BelongsTo<DeviceTelemetryLog, $this> */
    public function normalizedTelemetryLog(): BelongsTo
    {
        return $this->belongsTo(DeviceTelemetryLog::class, 'normalized_telemetry_log_id');
    }

    /** @return BelongsTo<User, $this> */
    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_user_id');
    }

    public function isAcknowledged(): bool
    {
        return $this->acknowledged_at !== null;
    }

    public function acknowledge(User $user, ?string $note = null): void
    {
        if ($this->isAcknowledged()) {
            return;
        }

        $this->forceFill([
            'acknowledged_at' => now(),
            'acknowledged_by_user_id' => $user->getKey(),
            'acknowledgement_note' => is_string($note) && trim($note) !== '' ? trim($note) : null,
        ])->save();
    }

    /**
     * @param  Builder<Alert>  $query
     * @return Builder<Alert>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('normalized_at');
    }

    public function isOpen(): bool
    {
        return $this->normalized_at === null;
    }

    public function statusLabel(): string
    {
        return $this->isOpen() ? 'Open' : 'Normalized';
    }

    public function statusColor(): string
    {
        return $this->isOpen() ? 'danger' : 'success';
    }

    public function durationLabel(): string
    {
        $alertedAt = $this->resolveCarbon($this->getAttribute('alerted_at'));

        if (! $alertedAt instanceof CarbonInterface) {
            return '—';
        }

        $normalizedAt = $this->resolveCarbon($this->getAttribute('normalized_at'));
        $endsAt = $normalizedAt instanceof CarbonInterface ? $normalizedAt : now();
        $durationInSeconds = max(0, $alertedAt->diffInSeconds($endsAt));

        return CarbonInterval::seconds($durationInSeconds)->cascade()->forHumans();
    }

    private function resolveCarbon(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_string($value) && trim($value) !== '') {
            return Carbon::parse($value);
        }

        return null;
    }
}
