<?php

declare(strict_types=1);

use App\Domain\Authorization\Enums\TenantRole;
use App\Domain\Authorization\Permissions\RolePermission;
use App\Domain\Authorization\Services\TenantRoleManager;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use App\Domain\Reporting\Enums\ReportRunStatus;
use App\Domain\Reporting\Enums\ReportType;
use App\Domain\Reporting\Jobs\GenerateReportRunJob;
use App\Domain\Reporting\Models\ReportRun;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use App\Domain\Shared\Permissions\UserPermission;
use App\Filament\Portal\Pages\Reports;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->organization = Organization::factory()->create(['slug' => 'portal-reports-primary']);
    $this->otherOrganization = Organization::factory()->create(['slug' => 'portal-reports-other']);
    $this->portalUser = User::factory()->create();
    $this->portalUser->organizations()->attach($this->organization);
    $this->otherPortalUser = User::factory()->create();
    $this->otherPortalUser->organizations()->attach($this->otherOrganization);
    app(TenantRoleManager::class)->assign($this->portalUser, $this->organization, TenantRole::Operator);
    app(TenantRoleManager::class)->assign($this->otherPortalUser, $this->otherOrganization, TenantRole::Viewer);
    $this->superAdmin = User::factory()->create(['is_super_admin' => true]);
    $this->device = Device::factory()->create(['organization_id' => $this->organization->id]);
    $this->otherDevice = Device::factory()->create(['organization_id' => $this->otherOrganization->id]);

    foreach ([$this->device, $this->otherDevice] as $device) {
        $channel = DeviceChannel::factory()
            ->publish()
            ->create(['device_profile_version_id' => $device->device_profile_version_id]);

        ProfileParameterDefinition::factory()
            ->for($channel, 'channel')
            ->create([
                'key' => 'temperature',
                'label' => 'Temperature',
                'is_active' => true,
            ]);
    }

    foreach ([RolePermission::VIEW_ANY->value, UserPermission::VIEW_ANY->value] as $permissionName) {
        Permission::findOrCreate($permissionName, 'web');
    }

    $this->actingAs($this->portalUser);
    Filament::setCurrentPanel('portal');
    Filament::setTenant($this->organization);
    Filament::bootCurrentPanel();
});

it('shows only current tenant reports and read-only portal controls', function (): void {
    $report = ReportRun::factory()->create([
        'organization_id' => $this->organization->id,
        'device_id' => $this->device->id,
        'requested_by_user_id' => $this->portalUser->id,
    ]);
    $otherReport = ReportRun::factory()->create([
        'organization_id' => $this->otherOrganization->id,
        'device_id' => $this->otherDevice->id,
        'requested_by_user_id' => $this->otherPortalUser->id,
    ]);

    Livewire::test(Reports::class)
        ->assertCanSeeTableRecords([$report])
        ->assertCanNotSeeTableRecords([$otherReport])
        ->assertActionVisible('generateReport')
        ->assertActionDoesNotExist('reportSettings')
        ->assertTableColumnHidden('organization.name')
        ->assertTableFilterHidden('organization_id');

    $this->get(route('filament.portal.pages.reports', ['tenant' => $this->organization]))
        ->assertSuccessful()
        ->assertDontSee('Report Pipeline')
        ->assertDontSee('Settings');
});

it('hides organization selection and injects the current tenant when generating a report', function (): void {
    Queue::fake();

    Livewire::test(Reports::class)
        ->mountAction('generateReport')
        ->assertSchemaComponentHidden('organization_id')
        ->fillForm([
            'organization_id' => $this->otherOrganization->id,
            'device_id' => $this->device->id,
            'type' => ReportType::ParameterValues->value,
            'grouping' => null,
            'parameter_keys' => [],
            'from_at' => now()->subDay()->toDateString(),
            'until_at' => now()->subDay()->toDateString(),
            'timezone' => 'UTC',
        ])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    $report = ReportRun::query()->sole();

    expect((int) $report->organization_id)->toBe((int) $this->organization->id)
        ->and((int) $report->device_id)->toBe((int) $this->device->id);

    Queue::assertPushed(GenerateReportRunJob::class);
});

it('rejects a cross-tenant device even when livewire state is tampered with', function (): void {
    Queue::fake();

    Livewire::test(Reports::class)
        ->callAction('generateReport', [
            'organization_id' => $this->otherOrganization->id,
            'device_id' => $this->otherDevice->id,
            'type' => ReportType::ParameterValues->value,
            'grouping' => null,
            'parameter_keys' => [],
            'from_at' => now()->subDay()->toDateString(),
            'until_at' => now()->subDay()->toDateString(),
            'timezone' => 'UTC',
        ]);

    expect(ReportRun::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('allows tenant downloads and denies cross-tenant report routes', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('reports/portal-report.csv', "timestamp,value\n2026-07-21,1\n");

    $report = ReportRun::factory()->completed()->create([
        'organization_id' => $this->organization->id,
        'device_id' => $this->device->id,
        'requested_by_user_id' => $this->portalUser->id,
        'storage_path' => 'reports/portal-report.csv',
        'file_name' => 'portal-report.csv',
    ]);
    $otherReport = ReportRun::factory()->completed()->create([
        'organization_id' => $this->otherOrganization->id,
        'device_id' => $this->otherDevice->id,
        'requested_by_user_id' => $this->otherPortalUser->id,
        'storage_path' => 'reports/other-report.csv',
        'file_name' => 'other-report.csv',
    ]);

    $this->get(route('portal.reporting.report-runs.download', [
        'organization' => $this->organization,
        'reportRun' => $report,
    ]))
        ->assertSuccessful()
        ->assertDownload('portal-report.csv');

    $this->get(route('portal.reporting.report-runs.download', [
        'organization' => $this->otherOrganization,
        'reportRun' => $otherReport,
    ]))->assertForbidden();

    $this->get(route('portal.reporting.report-runs.download', [
        'organization' => $this->organization,
        'reportRun' => $otherReport,
    ]))->assertNotFound();
});

it('keeps report changes and deletion restricted to super administrators', function (): void {
    $report = ReportRun::factory()->create([
        'organization_id' => $this->organization->id,
        'device_id' => $this->device->id,
        'requested_by_user_id' => $this->portalUser->id,
        'status' => ReportRunStatus::Completed,
    ]);

    expect($this->portalUser->can('viewAny', ReportRun::class))->toBeTrue()
        ->and($this->portalUser->can('create', ReportRun::class))->toBeTrue()
        ->and($this->portalUser->can('view', $report))->toBeTrue()
        ->and($this->portalUser->can('update', $report))->toBeFalse()
        ->and($this->portalUser->can('delete', $report))->toBeFalse()
        ->and($this->superAdmin->can('update', $report))->toBeTrue()
        ->and($this->superAdmin->can('delete', $report))->toBeTrue();
});
