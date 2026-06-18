<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use App\Domain\Telemetry\Services\TelemetryQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-17 12:00:00', 'UTC'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @return array{device: Device, otherDevice: Device, topic: SchemaVersionTopic, otherTopic: SchemaVersionTopic}
 */
function createTelemetryQueryServiceContext(): array
{
    $schemaVersion = DeviceSchemaVersion::factory()->create();
    $device = Device::factory()->create([
        'device_schema_version_id' => $schemaVersion->id,
    ]);
    $otherDevice = Device::factory()->create([
        'organization_id' => $device->organization_id,
        'device_schema_version_id' => $schemaVersion->id,
    ]);
    $topic = SchemaVersionTopic::factory()->publish()->create([
        'device_schema_version_id' => $schemaVersion->id,
        'key' => 'telemetry',
        'suffix' => 'telemetry',
    ]);
    $otherTopic = SchemaVersionTopic::factory()->publish()->create([
        'device_schema_version_id' => $schemaVersion->id,
        'key' => 'status',
        'suffix' => 'status',
    ]);

    return [
        'device' => $device,
        'otherDevice' => $otherDevice,
        'topic' => $topic,
        'otherTopic' => $otherTopic,
    ];
}

it('loads only the latest telemetry log for each requested device topic pair', function (): void {
    $context = createTelemetryQueryServiceContext();
    $service = app(TelemetryQueryService::class);

    DeviceTelemetryLog::factory()
        ->forDevice($context['device'])
        ->forTopic($context['topic'])
        ->create([
            'recorded_at' => now()->subMinutes(20),
            'transformed_values' => ['temp_c' => 19.2],
        ]);

    $latestTelemetryLog = DeviceTelemetryLog::factory()
        ->forDevice($context['device'])
        ->forTopic($context['topic'])
        ->create([
            'recorded_at' => now()->subMinutes(5),
            'transformed_values' => ['temp_c' => 22.4],
        ]);

    $latestStatusLog = DeviceTelemetryLog::factory()
        ->forDevice($context['device'])
        ->forTopic($context['otherTopic'])
        ->create([
            'recorded_at' => now()->subMinutes(3),
            'transformed_values' => ['status' => 1],
        ]);

    DeviceTelemetryLog::factory()
        ->forDevice($context['otherDevice'])
        ->forTopic($context['topic'])
        ->create([
            'recorded_at' => now()->subMinute(),
            'transformed_values' => ['temp_c' => 99],
        ]);

    $logs = $service->latestLogsForPairs([
        ['device_id' => $context['device']->id, 'topic_id' => $context['topic']->id],
        ['device_id' => $context['device']->id, 'topic_id' => $context['topic']->id],
        ['device_id' => $context['device']->id, 'topic_id' => $context['otherTopic']->id],
        ['device_id' => 0, 'topic_id' => $context['topic']->id],
    ], lookbackMinutes: 60);

    $telemetryKey = $service->pairKey((int) $context['device']->id, (int) $context['topic']->id);
    $statusKey = $service->pairKey((int) $context['device']->id, (int) $context['otherTopic']->id);

    expect($logs)->toHaveCount(2)
        ->and($logs->get($telemetryKey)?->id)->toBe($latestTelemetryLog->id)
        ->and($logs->get($statusKey)?->id)->toBe($latestStatusLog->id);
});

it('returns bounded numeric series points in chronological order', function (): void {
    $context = createTelemetryQueryServiceContext();
    $service = app(TelemetryQueryService::class);

    foreach ([30 => 1.1, 20 => 2.2, 10 => '3.3'] as $minutesAgo => $value) {
        DeviceTelemetryLog::factory()
            ->forDevice($context['device'])
            ->forTopic($context['topic'])
            ->create([
                'recorded_at' => now()->subMinutes($minutesAgo),
                'transformed_values' => [
                    'energy' => [
                        'total' => $value,
                    ],
                ],
            ]);
    }

    DeviceTelemetryLog::factory()
        ->forDevice($context['device'])
        ->forTopic($context['topic'])
        ->create([
            'recorded_at' => now()->subMinutes(90),
            'transformed_values' => ['energy' => ['total' => 999]],
        ]);

    $points = $service->numericSeries(
        deviceId: (int) $context['device']->id,
        schemaVersionTopicId: (int) $context['topic']->id,
        parameterKey: 'energy.total',
        fromAt: now()->subHour(),
        untilAt: now(),
        maxPoints: 2,
    );

    expect($points)->toHaveCount(2)
        ->and(array_column($points, 'value'))->toBe([2.2, 3.3])
        ->and($points[0]['timestamp'])->toBe(now()->subMinutes(20)->toIso8601String())
        ->and($points[1]['timestamp'])->toBe(now()->subMinutes(10)->toIso8601String());
});

it('calculates a non-negative counter delta across the requested interval', function (): void {
    $context = createTelemetryQueryServiceContext();
    $service = app(TelemetryQueryService::class);

    DeviceTelemetryLog::factory()
        ->forDevice($context['device'])
        ->forTopic($context['topic'])
        ->create([
            'recorded_at' => now()->subMinutes(35),
            'transformed_values' => ['total_energy_kwh' => 100.0],
        ]);

    DeviceTelemetryLog::factory()
        ->forDevice($context['device'])
        ->forTopic($context['topic'])
        ->create([
            'recorded_at' => now()->subMinutes(5),
            'transformed_values' => ['total_energy_kwh' => 127.34],
        ]);

    $delta = $service->counterDelta(
        deviceId: (int) $context['device']->id,
        schemaVersionTopicId: (int) $context['topic']->id,
        parameterKey: 'total_energy_kwh',
        startAt: now()->subMinutes(30),
        endAt: now(),
        precision: 1,
    );

    expect($delta)->toBe(27.3);
});
