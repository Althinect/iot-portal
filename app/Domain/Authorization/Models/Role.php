<?php

declare(strict_types=1);

namespace App\Domain\Authorization\Models;

use App\Domain\Authorization\Enums\TenantRole;
use App\Domain\Shared\Models\Organization;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * @property string $name
 * @property int $organization_id
 * @property TenantRole|null $tenant_role_key
 * @property Organization|null $organization
 */
class Role extends SpatieRole
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    protected static function newFactory(): RoleFactory
    {
        return RoleFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_role_key' => TenantRole::class,
        ];
    }

    public function isProtectedTenantRole(): bool
    {
        return $this->tenant_role_key instanceof TenantRole;
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
