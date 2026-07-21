<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\Reporting\Enums\ReportRunStatus;
use App\Domain\Reporting\Enums\ReportType;
use App\Domain\Reporting\Models\ReportRun;
use App\Domain\Reporting\Services\ReportRunNotificationService;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use Filament\Notifications\BroadcastNotification;
use Filament\Notifications\DatabaseNotification;
use Filament\Notifications\Events\DatabaseNotificationsSent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

it('persists a completed report notification with a download action and dispatches realtime refresh event', function (): void {
    Event::fake([DatabaseNotificationsSent::class]);

    $organization = Organization::factory()->create();
    $requestedBy = User::factory()->create(['is_super_admin' => true]);
    $device = Device::factory()->create(['organization_id' => $organization->id]);

    $reportRun = ReportRun::query()->create([
        'organization_id' => $organization->id,
        'device_id' => $device->id,
        'requested_by_user_id' => $requestedBy->id,
        'type' => ReportType::ParameterValues,
        'status' => ReportRunStatus::Completed,
        'format' => 'csv',
        'from_at' => now()->subDay(),
        'until_at' => now(),
        'timezone' => 'UTC',
        'storage_disk' => 'local',
        'storage_path' => 'reports/sample.csv',
        'file_name' => 'sample.csv',
        'file_size' => 1024,
        'generated_at' => now(),
        'row_count' => 12,
    ]);

    app(ReportRunNotificationService::class)->sendForStatus($reportRun);

    $databaseNotification = $requestedBy->notifications()->latest()->first();

    expect($databaseNotification)->not()->toBeNull();

    $serializedData = json_encode($databaseNotification?->data ?? [], JSON_UNESCAPED_SLASHES);

    expect((string) $serializedData)
        ->toContain('Report is ready')
        ->toContain(route('filament.admin.pages.reports'))
        ->toContain(route('reporting.report-runs.download', ['reportRun' => $reportRun]));

    Event::assertDispatched(DatabaseNotificationsSent::class);
});

it('uses tenant portal links for a completed report requested by an organization user', function (): void {
    Event::fake([DatabaseNotificationsSent::class]);

    $organization = Organization::factory()->create();
    $requestedBy = User::factory()->create();
    $requestedBy->organizations()->attach($organization);
    $device = Device::factory()->create(['organization_id' => $organization->id]);

    $reportRun = ReportRun::query()->create([
        'organization_id' => $organization->id,
        'device_id' => $device->id,
        'requested_by_user_id' => $requestedBy->id,
        'type' => ReportType::ParameterValues,
        'status' => ReportRunStatus::Completed,
        'format' => 'csv',
        'from_at' => now()->subDay(),
        'until_at' => now(),
        'timezone' => 'UTC',
        'storage_disk' => 'local',
        'storage_path' => 'reports/portal-sample.csv',
        'file_name' => 'portal-sample.csv',
        'file_size' => 1024,
        'generated_at' => now(),
        'row_count' => 12,
    ]);

    app(ReportRunNotificationService::class)->sendForStatus($reportRun);

    $databaseNotification = $requestedBy->notifications()->latest()->first();
    $serializedData = json_encode($databaseNotification?->data ?? [], JSON_UNESCAPED_SLASHES);

    expect((string) $serializedData)
        ->toContain(route('filament.portal.pages.reports', ['tenant' => $organization]))
        ->toContain(route('portal.reporting.report-runs.download', [
            'organization' => $organization,
            'reportRun' => $reportRun,
        ]))
        ->not->toContain(route('filament.admin.pages.reports'));
});

it('persists a no-data report notification without a download action', function (): void {
    Event::fake([DatabaseNotificationsSent::class]);

    $organization = Organization::factory()->create();
    $requestedBy = User::factory()->create(['is_super_admin' => true]);
    $device = Device::factory()->create(['organization_id' => $organization->id]);

    $reportRun = ReportRun::query()->create([
        'organization_id' => $organization->id,
        'device_id' => $device->id,
        'requested_by_user_id' => $requestedBy->id,
        'type' => ReportType::ParameterValues,
        'status' => ReportRunStatus::NoData,
        'format' => 'csv',
        'from_at' => now()->subDay(),
        'until_at' => now(),
        'timezone' => 'UTC',
        'generated_at' => now(),
        'row_count' => 0,
    ]);

    app(ReportRunNotificationService::class)->sendForStatus($reportRun);

    $databaseNotification = $requestedBy->notifications()->latest()->first();

    expect($databaseNotification)->not()->toBeNull();

    $serializedData = json_encode($databaseNotification?->data ?? [], JSON_UNESCAPED_SLASHES);

    expect((string) $serializedData)
        ->toContain('Report finished with no data')
        ->toContain(route('filament.admin.pages.reports'))
        ->not->toContain(route('reporting.report-runs.download', ['reportRun' => $reportRun]));

    Event::assertDispatched(DatabaseNotificationsSent::class);
});

it('sends both broadcast and database channels for report completion notifications', function (): void {
    Notification::fake();
    Event::fake([DatabaseNotificationsSent::class]);

    $organization = Organization::factory()->create();
    $requestedBy = User::factory()->create();
    $device = Device::factory()->create(['organization_id' => $organization->id]);

    $reportRun = ReportRun::query()->create([
        'organization_id' => $organization->id,
        'device_id' => $device->id,
        'requested_by_user_id' => $requestedBy->id,
        'type' => ReportType::ParameterValues,
        'status' => ReportRunStatus::Completed,
        'format' => 'csv',
        'from_at' => now()->subDay(),
        'until_at' => now(),
        'timezone' => 'UTC',
        'storage_disk' => 'local',
        'storage_path' => 'reports/sample.csv',
        'file_name' => 'sample.csv',
        'file_size' => 1024,
        'generated_at' => now(),
        'row_count' => 12,
    ]);

    app(ReportRunNotificationService::class)->sendForStatus($reportRun);

    Notification::assertSentTo($requestedBy, BroadcastNotification::class);
    Notification::assertSentTo($requestedBy, DatabaseNotification::class);
    Event::assertDispatched(DatabaseNotificationsSent::class);
});
