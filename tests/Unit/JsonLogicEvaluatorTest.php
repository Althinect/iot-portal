<?php

declare(strict_types=1);

use App\Domain\DeviceProfile\Services\JsonLogicEvaluator;

it('decodes unsigned integers as signed twos-complement values', function (mixed $rawValue, int $bitLength, int $expected): void {
    $result = (new JsonLogicEvaluator)->evaluate([
        'decode_twos_complement' => [
            ['var' => 'val'],
            $bitLength,
        ],
    ], ['val' => $rawValue]);

    expect($result)->toBe($expected);
})->with([
    '32-bit negative counter' => [4294967196, 32, -100],
    '32-bit positive counter' => [100, 32, 100],
    '16-bit negative counter' => [65436, 16, -100],
]);

it('keeps already decoded signed twos-complement values unchanged', function (): void {
    $result = (new JsonLogicEvaluator)->evaluate([
        'twos_complement' => [
            ['var' => 'val'],
            32,
        ],
    ], ['val' => -100]);

    expect($result)->toBe(-100);
});
