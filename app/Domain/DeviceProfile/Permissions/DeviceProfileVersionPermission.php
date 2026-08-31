<?php

declare(strict_types=1);

namespace App\Domain\DeviceProfile\Permissions;

use Althinect\EnumPermission\Concerns\HasPermissionGroup;

enum DeviceProfileVersionPermission: string
{
    use HasPermissionGroup;

    case VIEW_ANY = 'DeviceProfileVersion.view-any';
    case VIEW = 'DeviceProfileVersion.view';
    case CREATE = 'DeviceProfileVersion.create';
    case UPDATE = 'DeviceProfileVersion.update';
    case DELETE = 'DeviceProfileVersion.delete';
    case RESTORE = 'DeviceProfileVersion.restore';
    case FORCE_DELETE = 'DeviceProfileVersion.force-delete';
    case ACTIVATE = 'DeviceProfileVersion.activate';
}
