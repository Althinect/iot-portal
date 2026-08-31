<?php

declare(strict_types=1);

namespace App\Domain\Shared\Permissions;

use Althinect\EnumPermission\Concerns\HasPermissionGroup;

enum EntityPermission: string
{
    use HasPermissionGroup;

    case VIEW_ANY = 'Entity.view-any';
    case VIEW = 'Entity.view';
    case CREATE = 'Entity.create';
    case UPDATE = 'Entity.update';
    case ARCHIVE = 'Entity.archive';
    case RESTORE = 'Entity.restore';
}
