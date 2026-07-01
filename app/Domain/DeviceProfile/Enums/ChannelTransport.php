<?php

declare(strict_types=1);

namespace App\Domain\DeviceProfile\Enums;

use Filament\Support\Contracts\HasLabel;

enum ChannelTransport: string implements HasLabel
{
    case Mqtt = 'mqtt';
    case Http = 'http';

    public function getLabel(): string
    {
        return match ($this) {
            self::Mqtt => 'MQTT',
            self::Http => 'HTTP',
        };
    }

    public function label(): string
    {
        return $this->getLabel();
    }
}
