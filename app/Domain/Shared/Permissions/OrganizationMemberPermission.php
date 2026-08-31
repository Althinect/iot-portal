<?php

declare(strict_types=1);

namespace App\Domain\Shared\Permissions;

use Althinect\EnumPermission\Concerns\HasPermissionGroup;

enum OrganizationMemberPermission: string
{
    use HasPermissionGroup;

    case VIEW_ANY = 'OrganizationMember.view-any';
    case INVITE = 'OrganizationMember.invite';
    case UPDATE_ROLE = 'OrganizationMember.update-role';
    case DETACH = 'OrganizationMember.detach';
}
