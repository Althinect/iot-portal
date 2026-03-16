<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use App\Domain\Telemetry\Services\TelemetryLogRecorder;
use App\Events\TelemetryReceived;
use Database\Seeders\DeviceSchemaSeeder;
use Database\Seeders\DeviceTelemetrySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

it('seeds one month of 15-minute energy meter telemetry with an incrementing counter', function (): void {
    Event::fake([TelemetryReceived::class]);

    $this->seed([
        DeviceSchemaSeeder::class,
        DeviceTelemetrySeeder::class,
    ]);

    $device = Device::query()
        ->where('external_id', 'main-energy-meter-01')
        ->first();

    expect($device)->not->toBeNull();

    $logs = DeviceTelemetryLog::query()
        ->where('device_id', $device?->id)
        ->orderBy('recorded_at')
        ->get();

    expect($logs->count())->toBeGreaterThanOrEqual(2688);

    $firstTwoLogs = $logs->take(2)->values();

    expect($firstTwoLogs)->toHaveCount(2)
        ->and(abs($firstTwoLogs[1]->recorded_at->diffInMinutes($firstTwoLogs[0]->recorded_at, false)))->toBe(15.0);

    $latestTransformedValues = (array) ($logs->last()?->transformed_values ?? []);

    expect($latestTransformedValues)->toHaveKeys([
        'V1',
        'V2',
        'V3',
        'A1',
        'A2',
        'A3',
        'total_energy_kwh',
        'meter_state',
    ]);

    $energyValues = $logs
        ->map(fn (DeviceTelemetryLog $log): float => (float) data_get($log->transformed_values, 'total_energy_kwh', 0.0))
        ->values();

    $hasDecrease = false;

    for ($index = 1; $index < $energyValues->count(); $index++) {
        if ($energyValues[$index] < $energyValues[$index - 1]) {
            $hasDecrease = true;

            break;
        }
    }

    expect($hasDecrease)->toBeFalse()
        ->and($energyValues->last())->toBeGreaterThan($energyValues->first());

    $states = $logs
        ->map(fn (DeviceTelemetryLog $log): ?string => data_get($log->transformed_values, 'meter_state'))
        ->filter(fn (?string $state): bool => is_string($state))
        ->unique()
        ->values()
        ->all();

    expect($states)->not->toBe([])
        ->and(array_diff($states, ['idle', 'normal', 'fault']))->toBe([]);
});

it('temporarily disables broadcasting while generating telemetry history', function (): void {
    config(['broadcasting.default' => 'reverb']);

    $broadcastConnections = [];

    $recorder = Mockery::mock(TelemetryLogRecorder::class);
    $recorder->shouldReceive('record')
        ->atLeast()
        ->once()
        ->andReturnUsing(function () use (&$broadcastConnections): DeviceTelemetryLog {
            $broadcastConnections[] = (string) config('broadcasting.default');

            return new DeviceTelemetryLog;
        });

    app()->instance(TelemetryLogRecorder::class, $recorder);

    $this->seed([
        DeviceSchemaSeeder::class,
        DeviceTelemetrySeeder::class,
    ]);

    expect($broadcastConnections)->not->toBe([])
        ->and(array_values(array_unique($broadcastConnections)))->toBe(['null'])
        ->and(config('broadcasting.default'))->toBe('reverb');
});
