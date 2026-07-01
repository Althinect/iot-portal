<?php

declare(strict_types=1);

namespace App\Domain\DeviceProfile\Enums;

enum ParameterDataType: string
{
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Boolean = 'boolean';
    case String = 'string';
    case Json = 'json';

    public function label(): string
    {
        return match ($this) {
            self::Integer => 'Integer',
            self::Decimal => 'Decimal',
            self::Boolean => 'Boolean',
            self::String => 'String',
            self::Json => 'JSON',
        };
    }
}
