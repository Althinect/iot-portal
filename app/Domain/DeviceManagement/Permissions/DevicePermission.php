<?php

declare(strict_types=1);

namespace App\Domain\DeviceManagement\Permissions;

use Althinect\EnumPermission\Concerns\HasPermissionGroup;

enum DevicePermission: string
{
    use HasPermissionGroup;

    case VIEW_ANY = 'Device.view-any';
    case VIEW = 'Device.view';
    case CREATE = 'Device.create';
    case UPDATE = 'Device.update';
    case DELETE = 'Device.delete';
    case RESTORE = 'Device.restore';
    case FORCE_DELETE = 'Device.force-delete';
    case CONTROL = 'Device.control';
    case VIEW_DIAGNOSTICS = 'Device.view-diagnostics';
    case PROVISION = 'Device.provision';
    case MANAGE_CREDENTIALS = 'Device.manage-credentials';
    case DECOMMISSION = 'Device.decommission';
    case REACTIVATE = 'Device.reactivate';
}
