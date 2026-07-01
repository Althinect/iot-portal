<?php

declare(strict_types=1);

namespace App\Domain\DeviceProfile\Models;

use App\Domain\Shared\Models\Organization;
use Database\Factories\Domain\DeviceProfile\Models\DeviceProfileFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int|null $organization_id
 * @property string $key
 * @property string $name
 * @property array<string, mixed>|null $tags
 */
class DeviceProfile extends Model
{
    /** @use HasFactory<DeviceProfileFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = ['id'];

    protected static function newFactory(): DeviceProfileFactory
    {
        return DeviceProfileFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tags' => 'array',
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
     * @return HasMany<DeviceProfileVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(DeviceProfileVersion::class, 'device_profile_id');
    }

    public function isGlobal(): bool
    {
        return $this->organization_id === null;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeGlobal(Builder $query): Builder
    {
        return $query->whereNull('organization_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForOrganization(Builder $query, int|Organization $organization): Builder
    {
        $organizationId = $organization instanceof Organization ? $organization->id : $organization;

        return $query->where('organization_id', $organizationId);
    }
}
