<?php

declare(strict_types=1);

namespace App\Domain\Automation\Permissions;

use Althinect\EnumPermission\Concerns\HasPermissionGroup;

enum AutomationThresholdPolicyPermission: string
{
    use HasPermissionGroup;

    case VIEW_ANY = 'AutomationThresholdPolicy.view-any';
    case VIEW = 'AutomationThresholdPolicy.view';
    case CREATE = 'AutomationThresholdPolicy.create';
    case UPDATE = 'AutomationThresholdPolicy.update';
    case ARCHIVE = 'AutomationThresholdPolicy.archive';
}
