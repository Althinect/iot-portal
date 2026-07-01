<?php

declare(strict_types=1);

namespace App\Domain\DeviceProfile\DTO;

/**
 * Result of deriving computed parameters from the mutated values.
 */
final readonly class DerivationResult
{
    /**
     * @param  array<string, mixed>  $derivedValues
     * @param  array<string, mixed>  $finalValues
     */
    public function __construct(
        public array $derivedValues,
        public array $finalValues,
    ) {}
}
