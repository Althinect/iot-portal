<?php

declare(strict_types=1);

namespace App\Domain\IoTDashboard\Permissions;

use Althinect\EnumPermission\Concerns\HasPermissionGroup;

enum IoTDashboardWidgetPermission: string
{
    use HasPermissionGroup;

    case VIEW = 'IoTDashboardWidget.view';
    case CREATE = 'IoTDashboardWidget.create';
    case UPDATE = 'IoTDashboardWidget.update';
    case DELETE = 'IoTDashboardWidget.delete';
    case LAYOUT = 'IoTDashboardWidget.layout';
}
