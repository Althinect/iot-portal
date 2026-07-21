<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reporting;

use App\Domain\Reporting\Actions\DownloadReportRunAction;
use App\Domain\Reporting\Models\ReportRun;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PortalReportRunDownloadController extends Controller
{
    public function __invoke(
        Request $request,
        Organization $organization,
        ReportRun $reportRun,
        DownloadReportRunAction $downloadReportRunAction,
    ): Response {
        $user = $request->user();

        abort_unless($user instanceof User && $user->canAccessTenant($organization), Response::HTTP_FORBIDDEN);
        abort_unless((int) $reportRun->organization_id === (int) $organization->id, Response::HTTP_NOT_FOUND);
        abort_unless($user->can('view', $reportRun), Response::HTTP_FORBIDDEN);

        return $downloadReportRunAction($reportRun);
    }
}
