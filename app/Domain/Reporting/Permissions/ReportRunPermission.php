<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Permissions;

use Althinect\EnumPermission\Concerns\HasPermissionGroup;

enum ReportRunPermission: string
{
    use HasPermissionGroup;

    case VIEW_ANY = 'ReportRun.view-any';
    case VIEW = 'ReportRun.view';
    case CREATE = 'ReportRun.create';
    case DOWNLOAD = 'ReportRun.download';
    case MANAGE_SETTINGS = 'ReportRun.manage-settings';
}
