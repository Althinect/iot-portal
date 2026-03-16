<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\Reporting\Actions\CreateReportRunAction;
use App\Domain\Reporting\Actions\DeleteReportRunAction;
use App\Domain\Reporting\Actions\DownloadReportRunAction;
use App\Domain\Reporting\Actions\UpdateOrganizationReportSettingsAction;
use App\Domain\Reporting\Enums\ReportRunStatus;
use App\Domain\Reporting\Enums\ReportType;
use App\Domain\Reporting\Models\OrganizationReportSetting;
use App\Domain\Reporting\Models\ReportRun;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('reporting.api.base_url', 'https://reporting.local');
    config()->set('reporting.api.token', 'reporting-token');
    config()->set('reporting.api.token_header', 'X-Reporting-Token');
});

it('creates a report run through the DDD action and internal API client', function (): void {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $device = Device::factory()->create(['organization_id' => $organization->id]);

    $reportRun = ReportRun::query()->create([
        'organization_id' => $organization->id,
        'device_id' => $device->id,
        'requested_by_user_id' => $user->id,
        'type' => ReportType::ParameterValues,
        'status' => ReportRunStatus::Queued,
        'format' => 'csv',
        'from_at' => now()->subDay(),
        'until_at' => now(),
        'timezone' => 'UTC',
    ]);

    Http::fake([
        'https://reporting.local/api/internal/reporting/report-runs' => Http::response([
            'data' => ['id' => $reportRun->id],
        ], 201),
    ]);

    $resolved = app(CreateReportRunAction::class)($user, [
        'organization_id' => $organization->id,
        'device_id' => $device->id,
        'type' => ReportType::ParameterValues->value,
        'from_at' => now()->subDay()->toIso8601String(),
        'until_at' => now()->toIso8601String(),
        'timezone' => 'UTC',
    ]);

    expect($resolved->is($reportRun))->toBeTrue();

    Http::assertSent(function (Request $request) use ($user): bool {
        return $request->url() === 'https://reporting.local/api/internal/reporting/report-runs'
            && $request->method() === 'POST'
            && $request->hasHeader('X-Reporting-Token', 'reporting-token')
            && (int) data_get($request->data(), 'requested_by_user_id') === $user->id;
    });
});

it('updates organization report settings through the DDD action', function (): void {
    $organization = Organization::factory()->create();
    $settings = OrganizationReportSetting::factory()->create([
        'organization_id' => $organization->id,
        'timezone' => 'UTC',
    ]);

    Http::fake([
        'https://reporting.local/api/internal/reporting/organization-report-settings' => Http::response([
            'data' => ['organization_id' => $organization->id],
        ], 200),
    ]);

    $resolved = app(UpdateOrganizationReportSettingsAction::class)([
        'organization_id' => $organization->id,
        'timezone' => 'Asia/Colombo',
        'max_range_days' => 31,
        'shift_schedules' => [],
    ]);

    expect($resolved->is($settings))->toBeTrue();

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://reporting.local/api/internal/reporting/organization-report-settings'
            && $request->method() === 'PUT';
    });
});

it('deletes a report run through the DDD action', function (): void {
    $organization = Organization::factory()->create();
    $device = Device::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create();

    $reportRun = ReportRun::query()->create([
        'organization_id' => $organization->id,
        'device_id' => $device->id,
        'requested_by_user_id' => $user->id,
        'type' => ReportType::ParameterValues,
        'status' => ReportRunStatus::Queued,
        'format' => 'csv',
        'from_at' => now()->subDay(),
        'until_at' => now(),
        'timezone' => 'UTC',
    ]);

    Http::fake([
        "https://reporting.local/api/internal/reporting/report-runs/{$reportRun->id}" => Http::response([], 204),
    ]);

    app(DeleteReportRunAction::class)($reportRun);

    Http::assertSent(function (Request $request) use ($reportRun): bool {
        return $request->method() === 'DELETE'
            && $request->url() === "https://reporting.local/api/internal/reporting/report-runs/{$reportRun->id}"
            && (int) data_get($request->data(), 'organization_id') === (int) $reportRun->organization_id;
    });
});

it('downloads a report through the DDD action', function (): void {
    $organization = Organization::factory()->create();
    $device = Device::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->create();

    $reportRun = ReportRun::query()->create([
        'organization_id' => $organization->id,
        'device_id' => $device->id,
        'requested_by_user_id' => $user->id,
        'type' => ReportType::ParameterValues,
        'status' => ReportRunStatus::Completed,
        'format' => 'csv',
        'from_at' => now()->subDay(),
        'until_at' => now(),
        'timezone' => 'UTC',
        'storage_disk' => 'local',
        'storage_path' => 'reports/sample.csv',
        'file_name' => 'sample.csv',
        'file_size' => 12,
    ]);

    Http::fake([
        'https://reporting.local/api/internal/reporting/report-runs/*/download*' => Http::response(
            "a,b\n1,2\n",
            200,
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        ),
    ]);

    $response = app(DownloadReportRunAction::class)($reportRun);

    expect($response->successful())->toBeTrue()
        ->and($response->body())->toContain("a,b\n1,2");
});
