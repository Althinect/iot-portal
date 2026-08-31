<?php

declare(strict_types=1);

namespace App\Domain\Authorization\Enums;

enum TenantRole: string
{
    case Viewer = 'viewer';
    case Operator = 'operator';
    case TenantAdmin = 'tenant-admin';

    public function label(): string
    {
        return match ($this) {
            self::Viewer => 'Viewer',
            self::Operator => 'Operator',
            self::TenantAdmin => 'Tenant Admin',
        };
    }
}
