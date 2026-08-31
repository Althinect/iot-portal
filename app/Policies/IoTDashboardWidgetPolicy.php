<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Authorization\Services\TenantAuthorization;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use App\Domain\IoTDashboard\Permissions\IoTDashboardWidgetPermission;
use App\Domain\Shared\Models\User;

class IoTDashboardWidgetPolicy
{
    public function __construct(private TenantAuthorization $authorization) {}

    public function create(User $user): bool
    {
        return $this->authorization->allows($user, IoTDashboardWidgetPermission::CREATE);
    }

    public function view(User $user, IoTDashboardWidget $widget): bool
    {
        return $this->allowsForWidget($user, IoTDashboardWidgetPermission::VIEW, $widget);
    }

    public function update(User $user, IoTDashboardWidget $widget): bool
    {
        return $this->allowsForWidget($user, IoTDashboardWidgetPermission::UPDATE, $widget);
    }

    public function delete(User $user, IoTDashboardWidget $widget): bool
    {
        return $this->allowsForWidget($user, IoTDashboardWidgetPermission::DELETE, $widget);
    }

    public function layout(User $user, IoTDashboardWidget $widget): bool
    {
        return $this->allowsForWidget($user, IoTDashboardWidgetPermission::LAYOUT, $widget);
    }

    private function allowsForWidget(
        User $user,
        IoTDashboardWidgetPermission $permission,
        IoTDashboardWidget $widget,
    ): bool {
        $widget->loadMissing('dashboard');

        return $widget->dashboard !== null
            && $this->authorization->allows(
                $user,
                $permission,
                $widget->dashboard->organization_id,
            );
    }
}
