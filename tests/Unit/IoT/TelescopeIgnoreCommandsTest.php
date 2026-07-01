<?php

declare(strict_types=1);

use App\Console\Commands\IoT\IngestTelemetryCommand;
use Laravel\Telescope\Telescope;
use Tests\TestCase;

uses(TestCase::class);

it('ignores long-running iot commands from telescope recording', function (): void {
    $ignoredCommands = config('telescope.ignore_commands');

    expect($ignoredCommands)
        ->toBeArray()
        ->toContain('iot:ingest-telemetry')
        ->toContain('iot:listen-for-device-states')
        ->toContain('iot:listen-for-device-presence')
        ->not->toContain('iot:mock-device');
});

it('stops telescope recording when the telemetry ingestion command boots', function (): void {
    Telescope::startRecording(loadMonitoredTags: false);

    expect(Telescope::isRecording())->toBeTrue();

    $command = new IngestTelemetryCommand;
    $method = new ReflectionMethod($command, 'disableTelescopeRecording');
    $method->invoke($command);

    expect(Telescope::isRecording())->toBeFalse();
});
