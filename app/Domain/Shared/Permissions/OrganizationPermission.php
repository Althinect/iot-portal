<?php

namespace App\Domain\Shared\Permissions;

use Althinect\EnumPermission\Concerns\HasPermissionGroup;

enum OrganizationPermission: string
{
    use HasPermissionGroup;

    case VIEW_ANY = 'Organization.view-any';
    case VIEW = 'Organization.view';
    case CREATE = 'Organization.create';
    case UPDATE = 'Organization.update';
    case DELETE = 'Organization.delete';
    case RESTORE = 'Organization.restore';
    case FORCE_DELETE = 'Organization.force-delete';
    case UPDATE_SETTINGS = 'Organization.update-settings';

}
