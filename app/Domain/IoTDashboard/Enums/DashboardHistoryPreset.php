<?php

declare(strict_types=1);

namespace App\Domain\IoTDashboard\Enums;

use Filament\Support\Contracts\HasLabel;

enum DashboardHistoryPreset: string implements HasLabel
{
    case Last6Hours = '6h';
    case Last12Hours = '12h';
    case Last24Hours = '24h';
    case Last2Days = '2d';
    case Last7Days = '7d';

    public function getLabel(): string
    {
        return match ($this) {
            self::Last6Hours => 'Last 6 hours',
            self::Last12Hours => 'Last 12 hours',
            self::Last24Hours => 'Last 24 hours',
            self::Last2Days => 'Last 2 days',
            self::Last7Days => 'Last 7 days',
        };
    }

    public function label(): string
    {
        return $this->getLabel();
    }

    public function relativeExpression(): string
    {
        return match ($this) {
            self::Last6Hours => 'now-6h',
            self::Last12Hours => 'now-12h',
            self::Last24Hours => 'now-24h',
            self::Last2Days => 'now-2d',
            self::Last7Days => 'now-7d',
        };
    }
}
