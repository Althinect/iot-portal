<?php

declare(strict_types=1);

namespace App\Domain\Alerts\Permissions;

use Althinect\EnumPermission\Concerns\HasPermissionGroup;

enum AlertPermission: string
{
    use HasPermissionGroup;

    case VIEW_ANY = 'Alert.view-any';
    case VIEW = 'Alert.view';
    case ACKNOWLEDGE = 'Alert.acknowledge';
}
