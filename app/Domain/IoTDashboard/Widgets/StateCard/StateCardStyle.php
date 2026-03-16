<?php

declare(strict_types=1);

namespace App\Domain\IoTDashboard\Widgets\StateCard;

use Filament\Support\Contracts\HasLabel;

enum StateCardStyle: string implements HasLabel
{
    case Toggle = 'toggle';
    case Pill = 'pill';
    case DotLabel = 'dot_label';

    public function getLabel(): string
    {
        return match ($this) {
            self::Toggle => 'Toggle',
            self::Pill => 'Pill',
            self::DotLabel => 'Dot + Label',
        };
    }
}
