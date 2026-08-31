<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\AcceptTenantInvitationController;
use App\Http\Controllers\IoTDashboard\IoTDashboardSnapshotsController;
use App\Http\Controllers\IoTDashboard\IoTDashboardWidgetLayoutController;
use App\Http\Controllers\IoTDashboard\PortalIoTDashboardSnapshotsController;
use App\Http\Controllers\IoTDashboard\PortalIoTDashboardWidgetLayoutController;
use App\Http\Controllers\Reporting\PortalReportRunDownloadController;
use App\Http\Controllers\Reporting\ReportRunDownloadController;
use App\Http\Middleware\SetPortalTenantContext;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::view('/demo/scada-dashboard', 'demo.scada-dashboard')
    ->name('demo.scada-dashboard');

Route::view('/demo/classic-scada-network', 'demo.classic-scada-network')
    ->name('demo.classic-scada-network');

Route::get('/portal/invitations/{invitation}/{token}', [AcceptTenantInvitationController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('portal.invitations.show');
Route::post('/portal/invitations/{invitation}/{token}', [AcceptTenantInvitationController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('portal.invitations.store');

Route::middleware('auth')
    ->prefix('admin/iot-dashboard')
    ->name('admin.iot-dashboard.')
    ->group(function (): void {
        Route::get('/dashboards/{dashboard}/snapshots', IoTDashboardSnapshotsController::class)
            ->name('dashboards.snapshots');

        Route::post('/dashboards/{dashboard}/widgets/{widget}/layout', IoTDashboardWidgetLayoutController::class)
            ->name('dashboards.widgets.layout');
    });

Route::middleware(['auth', SetPortalTenantContext::class])
    ->prefix('portal/{organization}/iot-dashboard')
    ->name('portal.iot-dashboard.')
    ->group(function (): void {
        Route::get('/dashboards/{dashboard}/snapshots', PortalIoTDashboardSnapshotsController::class)
            ->name('dashboards.snapshots');

        Route::post('/dashboards/{dashboard}/widgets/{widget}/layout', PortalIoTDashboardWidgetLayoutController::class)
            ->name('dashboards.widgets.layout');
    });

Route::middleware('auth')
    ->prefix('admin/reports')
    ->name('reporting.')
    ->group(function (): void {
        Route::get('/report-runs/{reportRun}/download', ReportRunDownloadController::class)
            ->name('report-runs.download');
    });

Route::middleware(['auth', SetPortalTenantContext::class])
    ->prefix('portal/{organization}/reports')
    ->name('portal.reporting.')
    ->group(function (): void {
        Route::get('/report-runs/{reportRun}/download', PortalReportRunDownloadController::class)
            ->name('report-runs.download');
    });
