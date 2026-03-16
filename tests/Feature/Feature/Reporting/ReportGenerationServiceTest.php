<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Models\DeviceType;
use App\Domain\DeviceSchema\Enums\ParameterCategory;
use App\Domain\DeviceSchema\Enums\ParameterDataType;
use App\Domain\DeviceSchema\Models\DeviceSchema;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Domain\DeviceSchema\Models\ParameterDefinition;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Domain\Reporting\Enums\ReportGrouping;
use App\Domain\Reporting\Enums\ReportRunStatus;
use App\Domain\Reporting\Enums\ReportType;
use App\Domain\Reporting\Models\ReportRun;
use App\Domain\Reporting\Services\ReportGenerationService;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use App\Domain\Telemetry\Enums\ValidationStatus;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('reporting.storage_disk', 'local');
    config()->set('reporting.storage_directory', 'reports-test');
    Storage::fake('local');
});

/**
 * @return array{
 *     organization: Organization,
 *     user: User,
 *     device: Device,
 *     topic: SchemaVersionTopic,
 * }
 */
function createReportGenerationContext(): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $user->organizations()->attach($organization->id);

    $deviceType = DeviceType::factory()->mqtt()->create();
    $schema = DeviceSchema::factory()->forDeviceType($deviceType)->create();
    $schemaVersion = DeviceSchemaVersion::factory()->active()->create([
        'device_schema_id' => $schema->id,
    ]);
    $topic = SchemaVersionTopic::factory()->publish()->create([
        'device_schema_version_id' => $schemaVersion->id,
        'key' => 'telemetry',
        'suffix' => 'telemetry',
    ]);

    ParameterDefinition::factory()->create([
        'schema_version_topic_id' => $topic->id,
        'key' => 'machine_state',
        'label' => 'Machine State',
        'type' => ParameterDataType::String,
        'category' => ParameterCategory::State,
        'is_active' => true,
        'sequence' => 1,
        'validation_rules' => ['category' => 'enum'],
    ]);

    ParameterDefinition::factory()->create([
        'schema_version_topic_id' => $topic->id,
        'key' => 'total_energy_kwh',
        'label' => 'Total Energy',
        'type' => ParameterDataType::Decimal,
        'category' => ParameterCategory::Counter,
        'is_active' => true,
        'sequence' => 2,
    ]);

    $device = Device::factory()->create([
        'organization_id' => $organization->id,
        'device_type_id' => $deviceType->id,
        'device_schema_version_id' => $schemaVersion->id,
        'name' => 'Main Generator',
    ]);

    return compact('organization', 'user', 'device', 'topic');
}

it('generates state utilization report with carry-forward state handling', function (): void {
    ['organization' => $organization, 'user' => $user, 'device' => $device, 'topic' => $topic] = createReportGenerationContext();

    $entries = [
        ['2026-02-17 07:50:00', 'idle'],
        ['2026-02-17 08:10:00', 'utilized'],
        ['2026-02-17 09:10:00', 'maintenance'],
        ['2026-02-17 10:00:00', 'idle'],
    ];

    foreach ($entries as [$recordedAt, $state]) {
        DeviceTelemetryLog::factory()
            ->forDevice($device)
            ->forTopic($topic)
            ->create([
                'recorded_at' => Carbon::parse($recordedAt),
                'received_at' => Carbon::parse($recordedAt)->addSecond(),
                'raw_payload' => ['status' => ['machine_state' => $state]],
                'transformed_values' => ['machine_state' => $state],
                'validation_status' => ValidationStatus::Valid,
            ]);
    }

    $reportRun = ReportRun::query()->create([
        'organization_id' => $organization->id,
        'device_id' => $device->id,
        'requested_by_user_id' => $user->id,
        'type' => ReportType::StateUtilization,
        'grouping' => ReportGrouping::Daily,
        'status' => ReportRunStatus::Running,
        'format' => 'csv',
        'parameter_keys' => ['machine_state'],
        'from_at' => Carbon::parse('2026-02-17 08:00:00'),
        'until_at' => Carbon::parse('2026-02-17 11:00:00'),
        'timezone' => 'UTC',
    ]);

    app(ReportGenerationService::class)->generate($reportRun);

    $reportRun->refresh();

    expect($reportRun->status)->toBe(ReportRunStatus::Completed)
        ->and($reportRun->row_count)->toBeGreaterThan(0)
        ->and($reportRun->storage_path)->not->toBeNull();

    $csv = Storage::disk('local')->get((string) $reportRun->storage_path);

    expect($csv)->toContain(
        'summary,"2026-02-17 00:00:00","2026-02-18 00:00:00",2026-02-17,machine_state,"Machine State",idle,4200,01:10:00,38.89',
    )->and($csv)->toContain(
        'summary,"2026-02-17 00:00:00","2026-02-18 00:00:00",2026-02-17,machine_state,"Machine State",maintenance,3000,00:50:00,27.78',
    )->and($csv)->toContain(
        'summary,"2026-02-17 00:00:00","2026-02-18 00:00:00",2026-02-17,machine_state,"Machine State",utilized,3600,01:00:00,33.33',
    )->and($csv)->toContain(
        'transition,,,,machine_state,"Machine State",,,,,"2026-02-17 08:10:00",idle,utilized',
    )->and($csv)->toContain(
        'transition,,,,machine_state,"Machine State",,,,,"2026-02-17 09:10:00",utilized,maintenance',
    );
});

it('generates hourly counter consumption from incremental telemetry values', function (): void {
    ['organization' => $organization, 'user' => $user, 'device' => $device, 'topic' => $topic] = createReportGenerationContext();

    $entries = [
        ['2026-02-17 00:00:00', 10.0],
        ['2026-02-17 00:30:00', 12.0],
        ['2026-02-17 01:00:00', 15.0],
        ['2026-02-17 01:30:00', 20.0],
    ];

    foreach ($entries as [$recordedAt, $counter]) {
        DeviceTelemetryLog::factory()
            ->forDevice($device)
            ->forTopic($topic)
            ->create([
                'recorded_at' => Carbon::parse($recordedAt),
                'received_at' => Carbon::parse($recordedAt)->addSecond(),
                'raw_payload' => ['energy' => ['total_energy_kwh' => $counter]],
                'transformed_values' => ['total_energy_kwh' => $counter],
                'validation_status' => ValidationStatus::Valid,
            ]);
    }

    $reportRun = ReportRun::query()->create([
        'organization_id' => $organization->id,
        'device_id' => $device->id,
        'requested_by_user_id' => $user->id,
        'type' => ReportType::CounterConsumption,
        'status' => ReportRunStatus::Running,
        'format' => 'csv',
        'grouping' => ReportGrouping::Hourly,
        'parameter_keys' => ['total_energy_kwh'],
        'from_at' => Carbon::parse('2026-02-17 00:00:00'),
        'until_at' => Carbon::parse('2026-02-17 02:00:00'),
        'timezone' => 'UTC',
    ]);

    app(ReportGenerationService::class)->generate($reportRun);

    $reportRun->refresh();
    $csv = Storage::disk('local')->get((string) $reportRun->storage_path);

    expect($csv)->toContain('bucket_start,bucket_end,window_name,parameter_key,parameter_label,sample_count,from_value,to_value,consumption')
        ->and($csv)->toContain('"2026-02-17 00:00:00","2026-02-17 01:00:00","2026-02-17 00:00",total_energy_kwh,"Total Energy",2,10,12,2')
        ->and($csv)->toContain('"2026-02-17 01:00:00","2026-02-17 02:00:00","2026-02-17 01:00",total_energy_kwh,"Total Energy",2,15,20,5');
});

it('generates shift schedule grouped counter consumption using configured shift order', function (): void {
    ['organization' => $organization, 'user' => $user, 'device' => $device, 'topic' => $topic] = createReportGenerationContext();

    $entries = [
        ['2026-02-17 05:00:00', 10.0],
        ['2026-02-17 07:00:00', 12.0],
        ['2026-02-17 13:00:00', 20.0],
        ['2026-02-17 15:00:00', 25.0],
        ['2026-02-17 21:00:00', 33.0],
        ['2026-02-17 23:00:00', 40.0],
    ];

    foreach ($entries as [$recordedAt, $counter]) {
        DeviceTelemetryLog::factory()
            ->forDevice($device)
            ->forTopic($topic)
            ->create([
                'recorded_at' => Carbon::parse($recordedAt),
                'received_at' => Carbon::parse($recordedAt)->addSecond(),
                'raw_payload' => ['energy' => ['total_energy_kwh' => $counter]],
                'transformed_values' => ['total_energy_kwh' => $counter],
                'validation_status' => ValidationStatus::Valid,
            ]);
    }

    $reportRun = ReportRun::query()->create([
        'organization_id' => $organization->id,
        'device_id' => $device->id,
        'requested_by_user_id' => $user->id,
        'type' => ReportType::CounterConsumption,
        'status' => ReportRunStatus::Running,
        'format' => 'csv',
        'grouping' => ReportGrouping::ShiftSchedule,
        'parameter_keys' => ['total_energy_kwh'],
        'from_at' => Carbon::parse('2026-02-17 00:00:00'),
        'until_at' => Carbon::parse('2026-02-18 00:00:00'),
        'timezone' => 'UTC',
        'payload' => [
            'shift_schedule' => [
                'id' => 'three-shift',
                'name' => 'Three Shift',
                'windows' => [
                    ['id' => 'a', 'name' => 'A', 'start' => '06:00', 'end' => '14:00'],
                    ['id' => 'b', 'name' => 'B', 'start' => '14:00', 'end' => '22:00'],
                    ['id' => 'c', 'name' => 'C', 'start' => '22:00', 'end' => '06:00'],
                ],
            ],
        ],
    ]);

    app(ReportGenerationService::class)->generate($reportRun);

    $reportRun->refresh();
    $csv = Storage::disk('local')->get((string) $reportRun->storage_path);

    expect($csv)->toContain('"2026-02-17 06:00:00","2026-02-17 14:00:00","Three Shift - A",total_energy_kwh,"Total Energy",2,12,20,8')
        ->and($csv)->toContain('"2026-02-17 14:00:00","2026-02-17 22:00:00","Three Shift - B",total_energy_kwh,"Total Energy",2,25,33,8')
        ->and($csv)->toContain('"2026-02-16 22:00:00","2026-02-17 06:00:00","Three Shift - C",total_energy_kwh,"Total Energy",1,10,10,0')
        ->and($csv)->toContain('"2026-02-17 22:00:00","2026-02-18 06:00:00","Three Shift - C",total_energy_kwh,"Total Energy",1,40,40,0');
});

it('marks a report run as no data and skips file generation when the result has zero rows', function (): void {
    ['organization' => $organization, 'user' => $user, 'device' => $device] = createReportGenerationContext();

    $reportRun = ReportRun::query()->create([
        'organization_id' => $organization->id,
        'device_id' => $device->id,
        'requested_by_user_id' => $user->id,
        'type' => ReportType::ParameterValues,
        'status' => ReportRunStatus::Running,
        'format' => 'csv',
        'parameter_keys' => ['total_energy_kwh'],
        'from_at' => Carbon::parse('2026-02-17 00:00:00'),
        'until_at' => Carbon::parse('2026-02-17 01:00:00'),
        'timezone' => 'UTC',
    ]);

    app(ReportGenerationService::class)->generate($reportRun);

    $reportRun->refresh();

    expect($reportRun->status)->toBe(ReportRunStatus::NoData)
        ->and($reportRun->row_count)->toBe(0)
        ->and($reportRun->storage_disk)->toBeNull()
        ->and($reportRun->storage_path)->toBeNull()
        ->and($reportRun->file_name)->toBeNull()
        ->and($reportRun->file_size)->toBeNull();

    expect(Storage::disk('local')->files('reports-test'))->toBe([]);
});

it('uses an exclusive end boundary for telemetry queries', function (): void {
    ['organization' => $organization, 'user' => $user, 'device' => $device, 'topic' => $topic] = createReportGenerationContext();

    DeviceTelemetryLog::factory()
        ->forDevice($device)
        ->forTopic($topic)
        ->create([
            'recorded_at' => Carbon::parse('2026-02-17 00:30:00'),
            'received_at' => Carbon::parse('2026-02-17 00:30:01'),
            'raw_payload' => ['energy' => ['total_energy_kwh' => 100.0]],
            'transformed_values' => ['total_energy_kwh' => 100.0],
            'validation_status' => ValidationStatus::Valid,
        ]);

    DeviceTelemetryLog::factory()
        ->forDevice($device)
        ->forTopic($topic)
        ->create([
            'recorded_at' => Carbon::parse('2026-02-17 01:00:00'),
            'received_at' => Carbon::parse('2026-02-17 01:00:01'),
            'raw_payload' => ['energy' => ['total_energy_kwh' => 130.0]],
            'transformed_values' => ['total_energy_kwh' => 130.0],
            'validation_status' => ValidationStatus::Valid,
        ]);

    $reportRun = ReportRun::query()->create([
        'organization_id' => $organization->id,
        'device_id' => $device->id,
        'requested_by_user_id' => $user->id,
        'type' => ReportType::CounterConsumption,
        'status' => ReportRunStatus::Running,
        'format' => 'csv',
        'grouping' => ReportGrouping::Hourly,
        'parameter_keys' => ['total_energy_kwh'],
        'from_at' => Carbon::parse('2026-02-17 00:00:00'),
        'until_at' => Carbon::parse('2026-02-17 01:00:00'),
        'timezone' => 'UTC',
    ]);

    app(ReportGenerationService::class)->generate($reportRun);

    $reportRun->refresh();
    $csv = Storage::disk('local')->get((string) $reportRun->storage_path);

    expect($csv)->toContain('"2026-02-17 00:00:00","2026-02-17 01:00:00","2026-02-17 00:00",total_energy_kwh,"Total Energy",1,100,100,0')
        ->and($csv)->not->toContain('130');
});

it('marks a report run as failed when writing the CSV to storage fails', function (): void {
    ['organization' => $organization, 'user' => $user, 'device' => $device, 'topic' => $topic] = createReportGenerationContext();

    DeviceTelemetryLog::factory()
        ->forDevice($device)
        ->forTopic($topic)
        ->create([
            'recorded_at' => Carbon::parse('2026-02-17 08:00:00'),
            'received_at' => Carbon::parse('2026-02-17 08:00:01'),
            'raw_payload' => ['energy' => ['total_energy_kwh' => 42.0]],
            'transformed_values' => ['total_energy_kwh' => 42.0],
            'validation_status' => ValidationStatus::Valid,
        ]);

    $reportRun = ReportRun::query()->create([
        'organization_id' => $organization->id,
        'device_id' => $device->id,
        'requested_by_user_id' => $user->id,
        'type' => ReportType::ParameterValues,
        'status' => ReportRunStatus::Running,
        'format' => 'csv',
        'parameter_keys' => ['total_energy_kwh'],
        'from_at' => Carbon::parse('2026-02-17 00:00:00'),
        'until_at' => Carbon::parse('2026-02-17 12:00:00'),
        'timezone' => 'UTC',
    ]);

    // Simulate Storage disk write failure
    $adapter = Mockery::mock(FilesystemAdapter::class);
    $adapter->shouldReceive('put')->andReturnFalse();
    Storage::shouldReceive('disk')->with('local')->andReturn($adapter);

    app(ReportGenerationService::class)->generate($reportRun);

    $reportRun->refresh();

    expect($reportRun->status)->toBe(ReportRunStatus::Failed)
        ->and($reportRun->failure_reason)->toContain('Failed to write report to disk')
        ->and($reportRun->storage_path)->toBeNull()
        ->and($reportRun->storage_disk)->toBeNull();
});
