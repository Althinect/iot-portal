<?php

declare(strict_types=1);

namespace App\Domain\DeviceProfile\Enums;

use Filament\Support\Contracts\HasLabel;

enum ParameterCategory: string implements HasLabel
{
    case Counter = 'counter';
    case State = 'state';
    case Measurement = 'measurement';

    public function getLabel(): string
    {
        return match ($this) {
            self::Counter => 'Counter',
            self::State => 'State',
            self::Measurement => 'Measurement',
        };
    }

    public static function fromLegacyCategory(?string $legacyCategory, ?string $parameterKey = null): self
    {
        $normalized = strtolower(trim((string) $legacyCategory));
        $normalizedKey = strtolower(trim((string) $parameterKey));

        return match (true) {
            $normalized === 'counter' => self::Counter,
            in_array($normalized, ['state', 'enum'], true) => self::State,
            $normalizedKey !== '' && str_contains($normalizedKey, 'state') => self::State,
            default => self::Measurement,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function getOptions(): array
    {
        return [
            self::Counter->value => self::Counter->getLabel(),
            self::State->value => self::State->getLabel(),
            self::Measurement->value => self::Measurement->getLabel(),
        ];
    }
}
