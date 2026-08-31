<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Alerts\Models\Alert;
use App\Domain\Alerts\Permissions\AlertPermission;
use App\Domain\Authorization\Services\TenantAuthorization;
use App\Domain\Shared\Models\User;

class AlertPolicy
{
    public function __construct(private TenantAuthorization $authorization) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->allows($user, AlertPermission::VIEW_ANY);
    }

    public function view(User $user, Alert $alert): bool
    {
        return $this->authorization->allows($user, AlertPermission::VIEW, $alert->organization_id);
    }

    public function acknowledge(User $user, Alert $alert): bool
    {
        return $this->authorization->allows($user, AlertPermission::ACKNOWLEDGE, $alert->organization_id);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Alert $alert): bool
    {
        return false;
    }

    public function delete(User $user, Alert $alert): bool
    {
        return false;
    }
}
