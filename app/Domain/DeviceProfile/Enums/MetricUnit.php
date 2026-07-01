<?php

declare(strict_types=1);

namespace App\Domain\DeviceProfile\Enums;

use Filament\Support\Contracts\HasLabel;

enum MetricUnit: string implements HasLabel
{
    case Celsius = 'Celsius';
    case Percent = 'Percent';
    case Volts = 'Volts';
    case Amperes = 'A';
    case KilowattHours = 'kWh';
    case Watts = 'Watts';
    case Seconds = 'Seconds';
    case DecibelMilliwatts = 'dBm';
    case RevolutionsPerMinute = 'RPM';
    case Litres = 'Litres';
    case CubicMeters = 'Cubic Meters';
    case LitersPerMinute = 'Liters per Minute';

    public function getLabel(): string
    {
        return match ($this) {
            self::Celsius => 'Celsius (°C)',
            self::Percent => 'Percent (%)',
            self::Volts => 'Volts (V)',
            self::Amperes => 'Amperes (A)',
            self::KilowattHours => 'Kilowatt-hours (kWh)',
            self::Watts => 'Watts (W)',
            self::Seconds => 'Seconds (s)',
            self::DecibelMilliwatts => 'Signal (dBm)',
            self::RevolutionsPerMinute => 'RPM',
            self::Litres => 'Litres (L)',
            self::CubicMeters => 'Cubic Meters (m³)',
            self::LitersPerMinute => 'Liters per Minute (L/min)',
        };
    }

    public function label(): string
    {
        return $this->getLabel();
    }
}
