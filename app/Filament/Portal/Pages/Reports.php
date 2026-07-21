<?php

declare(strict_types=1);

namespace App\Filament\Portal\Pages;

use App\Domain\Reporting\Models\ReportRun;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use App\Filament\Pages\Reports as BaseReports;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use LogicException;

class Reports extends BaseReports
{
    protected string $view = 'filament.portal.pages.reports';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return parent::canAccess()
            && $user instanceof User
            && $user->organizations()->exists();
    }

    /**
     * @return array<int, int>
     */
    protected function accessibleOrganizationIds(): array
    {
        $user = Auth::user();
        $tenant = Filament::getTenant();

        if (! $user instanceof User || ! $tenant instanceof Organization || ! $user->canAccessTenant($tenant)) {
            return [];
        }

        return [(int) $tenant->id];
    }

    protected function shouldShowOrganizationControls(): bool
    {
        return false;
    }

    protected function canManageReportSettings(): bool
    {
        return false;
    }

    protected function canDeleteReports(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function resolveActionOrganizationId(array $data): int
    {
        return $this->accessibleOrganizationIds()[0] ?? 0;
    }

    protected function resolveFormOrganizationId(mixed $organizationId): ?int
    {
        return $this->accessibleOrganizationIds()[0] ?? null;
    }

    protected function downloadUrl(ReportRun $reportRun): string
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Organization) {
            throw new LogicException('A current organization is required to download a Portal report.');
        }

        return route('portal.reporting.report-runs.download', [
            'organization' => $tenant,
            'reportRun' => $reportRun,
        ]);
    }
}
