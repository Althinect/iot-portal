<?php

declare(strict_types=1);

use App\Domain\Shared\Services\HorizonRuntimeConfigurator;
use App\Domain\Shared\Services\RuntimeSettingManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'horizon.defaults.supervisor-default.balance' => 'simple',
        'horizon.defaults.supervisor-default.processes' => 3,
        'horizon.defaults.supervisor-ingestion.balance' => 'simple',
        'horizon.defaults.supervisor-ingestion.processes' => 4,
        'horizon.defaults.supervisor-side-effects.balance' => 'simple',
        'horizon.defaults.supervisor-side-effects.processes' => 4,
        'horizon.defaults.supervisor-automation.balance' => 'simple',
        'horizon.defaults.supervisor-automation.processes' => 4,
        'horizon.defaults.supervisor-simulations.balance' => 'simple',
        'horizon.defaults.supervisor-simulations.processes' => 4,
        'horizon.auto_balancing.enabled' => false,
        'horizon.auto_balancing.strategy' => 'time',
        'horizon.auto_balancing.balance_max_shift' => 1,
        'horizon.auto_balancing.balance_cooldown' => 3,
        'horizon.auto_balancing.supervisors.default.max_processes' => 3,
        'horizon.auto_balancing.supervisors.ingestion.max_processes' => 4,
        'horizon.auto_balancing.supervisors.side_effects.max_processes' => 4,
        'horizon.auto_balancing.supervisors.automation.max_processes' => 4,
        'horizon.auto_balancing.supervisors.simulations.max_processes' => 4,
    ]);
});

it('leaves fixed horizon processes unchanged when auto balancing is disabled', function (): void {
    app(HorizonRuntimeConfigurator::class)->apply();

    expect(config('horizon.defaults.supervisor-ingestion.balance'))->toBe('simple')
        ->and(config('horizon.defaults.supervisor-ingestion.processes'))->toBe(4)
        ->and(config('horizon.defaults.supervisor-ingestion.maxProcesses'))->toBeNull();
});

it('applies auto balancing caps from runtime settings when enabled', function (): void {
    app(RuntimeSettingManager::class)->setGlobalOverrides([
        'horizon.auto_balancing.enabled' => true,
        'horizon.default.max_processes' => 6,
        'horizon.ingestion.max_processes' => 12,
        'horizon.side_effects.max_processes' => 10,
        'horizon.automation.max_processes' => 7,
        'horizon.simulations.max_processes' => 2,
    ]);

    app(HorizonRuntimeConfigurator::class)->apply();

    expect(config('horizon.defaults.supervisor-default.balance'))->toBe('auto')
        ->and(config('horizon.defaults.supervisor-default.minProcesses'))->toBe(1)
        ->and(config('horizon.defaults.supervisor-default.maxProcesses'))->toBe(6)
        ->and(config('horizon.defaults.supervisor-default.autoScalingStrategy'))->toBe('time')
        ->and(config('horizon.defaults.supervisor-default.balanceMaxShift'))->toBe(1)
        ->and(config('horizon.defaults.supervisor-default.balanceCooldown'))->toBe(3)
        ->and(config('horizon.defaults.supervisor-ingestion.maxProcesses'))->toBe(12)
        ->and(config('horizon.defaults.supervisor-side-effects.maxProcesses'))->toBe(10)
        ->and(config('horizon.defaults.supervisor-automation.maxProcesses'))->toBe(7)
        ->and(config('horizon.defaults.supervisor-simulations.maxProcesses'))->toBe(2);
});
