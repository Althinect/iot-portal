<?php

declare(strict_types=1);

namespace App\Domain\Shared\Services;

class HorizonRuntimeConfigurator
{
    /**
     * @var array<string, string>
     */
    private const SUPERVISOR_MAP = [
        'default' => 'supervisor-default',
        'ingestion' => 'supervisor-ingestion',
        'side_effects' => 'supervisor-side-effects',
        'automation' => 'supervisor-automation',
        'simulations' => 'supervisor-simulations',
    ];

    public function __construct(
        private readonly RuntimeSettingManager $runtimeSettings,
    ) {}

    public function apply(): void
    {
        if (! $this->runtimeSettings->booleanValue('horizon.auto_balancing.enabled')) {
            return;
        }

        $strategy = $this->resolveStringConfig('horizon.auto_balancing.strategy', 'time');
        $balanceMaxShift = $this->resolveIntConfig('horizon.auto_balancing.balance_max_shift', 1);
        $balanceCooldown = $this->resolveIntConfig('horizon.auto_balancing.balance_cooldown', 3);

        foreach (self::SUPERVISOR_MAP as $settingKey => $supervisorName) {
            config([
                "horizon.defaults.{$supervisorName}.balance" => 'auto',
                "horizon.defaults.{$supervisorName}.minProcesses" => 1,
                "horizon.defaults.{$supervisorName}.maxProcesses" => $this->runtimeSettings->intValue("horizon.{$settingKey}.max_processes"),
                "horizon.defaults.{$supervisorName}.autoScalingStrategy" => $strategy,
                "horizon.defaults.{$supervisorName}.balanceMaxShift" => $balanceMaxShift,
                "horizon.defaults.{$supervisorName}.balanceCooldown" => $balanceCooldown,
            ]);
        }
    }

    private function resolveIntConfig(string $key, int $fallback): int
    {
        $value = config($key, $fallback);

        return is_numeric($value) ? (int) $value : $fallback;
    }

    private function resolveStringConfig(string $key, string $fallback): string
    {
        $value = config($key, $fallback);

        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : $fallback;
    }
}
