<?php

declare(strict_types=1);

namespace App\Domain\Shared\Models;

use App\Domain\Authorization\Models\Role;
use App\Domain\IoTDashboard\Models\IoTDashboard;
use Database\Factories\OrganizationFactory;
use Filament\Models\Contracts\HasAvatar;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Organization extends Model implements HasAvatar
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = ['id'];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function ($model): void {
            $model->uuid = Str::uuid();
        });
    }

    protected static function newFactory(): OrganizationFactory
    {
        return OrganizationFactory::new();
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function getFilamentAvatarUrl(): ?string
    {
        if (! $this->logo) {
            return null;
        }

        return Storage::url($this->logo);
    }

    /**
     * @return HasMany<Role, $this>
     * */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    /**
     * @return HasMany<IoTDashboard, $this>
     */
    public function dashboards(): HasMany
    {
        return $this->hasMany(IoTDashboard::class);
    }
}
