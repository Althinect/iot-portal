<?php

declare(strict_types=1);

namespace App\Domain\DeviceProfile\DTO;

/**
 * Result of applying mutation expressions to the extracted values.
 */
final readonly class MutationResult
{
    /**
     * @param  array<string, mixed>  $mutatedValues
     * @param  array<string, array{before: mixed, after: mixed}>  $changeSet
     */
    public function __construct(
        public array $mutatedValues,
        public array $changeSet,
    ) {}
}
