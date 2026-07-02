<?php

declare(strict_types=1);

use Tests\TestCase;

uses(TestCase::class);

it('ignores remaining long-running iot commands for telescope recording', function (): void {
    $ignoredCommands = config('telescope.ignore_commands');

    expect($ignoredCommands)
        ->toBeArray()
        ->toContain('iot:listen-for-device-states')
        ->toContain('iot:listen-for-device-presence')
        ->not->toContain('iot:ingest-telemetry')
        ->not->toContain('iot:mock-device');
});
