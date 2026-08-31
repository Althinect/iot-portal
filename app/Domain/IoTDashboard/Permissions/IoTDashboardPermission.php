<?php

declare(strict_types=1);

namespace App\Domain\IoTDashboard\Permissions;

use Althinect\EnumPermission\Concerns\HasPermissionGroup;

enum IoTDashboardPermission: string
{
    use HasPermissionGroup;

    case VIEW_ANY = 'IoTDashboard.view-any';
    case VIEW = 'IoTDashboard.view';
    case CREATE = 'IoTDashboard.create';
    case UPDATE = 'IoTDashboard.update';
    case ARCHIVE = 'IoTDashboard.archive';
    case RESTORE = 'IoTDashboard.restore';
}
