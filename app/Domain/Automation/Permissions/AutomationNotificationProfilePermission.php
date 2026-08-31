<?php

declare(strict_types=1);

namespace App\Domain\Automation\Permissions;

use Althinect\EnumPermission\Concerns\HasPermissionGroup;

enum AutomationNotificationProfilePermission: string
{
    use HasPermissionGroup;

    case VIEW_ANY = 'AutomationNotificationProfile.view-any';
    case VIEW = 'AutomationNotificationProfile.view';
    case CREATE = 'AutomationNotificationProfile.create';
    case UPDATE = 'AutomationNotificationProfile.update';
    case ARCHIVE = 'AutomationNotificationProfile.archive';
}
