<?php

declare(strict_types=1);

namespace App\Domain\Automation\Permissions;

use Althinect\EnumPermission\Concerns\HasPermissionGroup;

enum AutomationWorkflowPermission: string
{
    use HasPermissionGroup;

    case VIEW_ANY = 'AutomationWorkflow.view-any';
    case VIEW = 'AutomationWorkflow.view';
    case CREATE = 'AutomationWorkflow.create';
    case UPDATE = 'AutomationWorkflow.update';
    case DELETE = 'AutomationWorkflow.delete';
    case RESTORE = 'AutomationWorkflow.restore';
    case FORCE_DELETE = 'AutomationWorkflow.force-delete';
    case PUBLISH = 'AutomationWorkflow.publish';
    case ARCHIVE = 'AutomationWorkflow.archive';
}
