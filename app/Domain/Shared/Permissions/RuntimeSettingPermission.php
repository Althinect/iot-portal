<?php

declare(strict_types=1);

namespace App\Domain\Shared\Permissions;

use Althinect\EnumPermission\Concerns\HasPermissionGroup;

enum RuntimeSettingPermission: string
{
    use HasPermissionGroup;

    case VIEW = 'RuntimeSettings.view';
    case UPDATE_GLOBAL = 'RuntimeSettings.update-global';
    case UPDATE_ORGANIZATION = 'RuntimeSettings.update-organization';
}
