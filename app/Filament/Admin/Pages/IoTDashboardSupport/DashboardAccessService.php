<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\IoTDashboardSupport;

use App\Domain\IoTDashboard\Models\IoTDashboard;
use App\Domain\Shared\Models\User;
use Illuminate\Database\Eloquent\Builder;

class DashboardAccessService
{
    public function resolveInitialDashboardId(?int $requestedDashboardId, User $user): ?int
    {
        if (is_int($requestedDashboardId) && $requestedDashboardId > 0) {
            $exists = $this->queryForUser($user)
                ->whereKey($requestedDashboardId)
                ->exists();

            if ($exists) {
                return $requestedDashboardId;
            }
        }

        $firstDashboardId = $this->queryForUser($user)
            ->orderBy('name')
            ->value('id');

        return is_numeric($firstDashboardId)
            ? (int) $firstDashboardId
            : null;
    }

    public function selectedDashboard(?int $dashboardId, User $user): ?IoTDashboard
    {
        if (! is_int($dashboardId) || $dashboardId < 1) {
            return null;
        }

        $dashboard = $this->queryForUser($user)
            ->with([
                'organization:id,name',
                'widgets' => fn ($query) => $query
                    ->with([
                        'topic:id,label,suffix',
                        'device:id,uuid,name,organization_id,external_id,connection_state,last_seen_at,offline_deadline_at,presence_timeout_seconds',
                    ])
                    ->orderBy('sequence')
                    ->orderBy('id'),
            ])
            ->find($dashboardId);

        return $dashboard instanceof IoTDashboard ? $dashboard : null;
    }

    /**
     * @return Builder<IoTDashboard>
     */
    public function queryForUser(User $user): Builder
    {
        $query = IoTDashboard::query();

        if ($user->isSuperAdmin()) {
            return $query;
        }

        return $query->whereIn('organization_id', $user->organizations()->pluck('id'));
    }
}
