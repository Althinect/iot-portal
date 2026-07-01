<?php

declare(strict_types=1);

namespace App\Domain\DeviceProfile\Enums;

use Filament\Support\Contracts\HasLabel;

enum ChannelPurpose: string implements HasLabel
{
    case Command = 'command';
    case State = 'state';
    case Telemetry = 'telemetry';
    case Event = 'event';
    case Ack = 'ack';

    public function getLabel(): string
    {
        return match ($this) {
            self::Command => 'Command',
            self::State => 'State',
            self::Telemetry => 'Telemetry',
            self::Event => 'Event',
            self::Ack => 'Acknowledgement',
        };
    }

    public function label(): string
    {
        return $this->getLabel();
    }
}
