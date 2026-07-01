<?php

declare(strict_types=1);

namespace App\Domain\DeviceProfile\Services;

class JsonLogicEvaluator
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function evaluate(mixed $expression, array $data = []): mixed
    {
        if (! is_array($expression)) {
            return $expression;
        }

        if (! $this->isAssoc($expression)) {
            return array_map(fn (mixed $item) => $this->evaluate($item, $data), $expression);
        }

        if (count($expression) !== 1) {
            return $expression;
        }

        $operator = array_key_first($expression);
        $values = $expression[$operator];

        return $this->applyOperator((string) $operator, $values, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyOperator(string $operator, mixed $values, array $data): mixed
    {
        return match ($operator) {
            'var' => $this->resolveVar($values, $data),
            '+', '-', '*', '/', 'min', 'max' => $this->applyNumericOperator($operator, $values, $data),
            '==', '===', '!=', '!==', '>', '>=', '<', '<=' => $this->applyComparisonOperator($operator, $values, $data),
            'and', 'or', '!' => $this->applyLogicalOperator($operator, $values, $data),
            'if' => $this->applyIf($values, $data),
            'reinterpret_big_endian_float', 'decode_big_endian_float' => $this->applyReinterpretBigEndianFloat($values, $data),
            'decode_twos_complement', 'twos_complement', 'twosComplement' => $this->applyDecodeTwosComplement($values, $data),
            default => $values,
        };
    }

    /**
     * Reinterprets an unsigned integer bit pattern as a big-endian IEEE-754 float.
     *
     * This is useful when upstream systems emit the numeric bit-pattern instead of
     * the decoded engineering value, while schema mutations still need to normalize
     * the device-type-specific payload.
     *
     * @param  array<string, mixed>  $data
     */
    private function applyReinterpretBigEndianFloat(mixed $values, array $data): mixed
    {
        $items = is_array($values) ? array_values($values) : [$values];
        $rawValue = $this->evaluate($items[0] ?? null, $data);
        $mode = $this->evaluate($items[1] ?? 'auto', $data);

        $integerValue = $this->normalizeUnsignedInteger($rawValue);

        if ($integerValue === null) {
            return $rawValue;
        }

        $decodedValue = match ($mode) {
            4, '4', 'float32', '32' => $this->reinterpretBigEndianFloatFromInteger($integerValue, 4),
            8, '8', 'float64', '64' => $this->reinterpretBigEndianFloatFromInteger($integerValue, 8),
            default => $integerValue <= 0xFFFFFFFF
                ? $this->reinterpretBigEndianFloatFromInteger($integerValue, 4)
                : $this->reinterpretBigEndianFloatFromInteger($integerValue, 8),
        };

        return $decodedValue ?? $rawValue;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveVar(mixed $values, array $data): mixed
    {
        if (is_array($values)) {
            $path = $values[0] ?? null;
            $default = $values[1] ?? null;
        } else {
            $path = $values;
            $default = null;
        }

        if (! is_string($path) || $path === '') {
            return $default;
        }

        return data_get($data, $path, $default);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyNumericOperator(string $operator, mixed $values, array $data): mixed
    {
        $items = is_array($values) ? $values : [$values];

        $evaluated = array_map(fn (mixed $item) => $this->evaluate($item, $data), $items);
        $numbers = array_map([$this, 'toNumber'], $evaluated);

        return match ($operator) {
            '+' => array_sum($numbers),
            '-' => $this->subtract($numbers),
            '*' => $this->multiply($numbers),
            '/' => $this->divide($numbers),
            'min' => $numbers === [] ? null : min($numbers),
            'max' => $numbers === [] ? null : max($numbers),
            default => null,
        };
    }

    /**
     * @param  array<int, float>  $numbers
     */
    private function subtract(array $numbers): float
    {
        $result = array_shift($numbers) ?? 0.0;

        foreach ($numbers as $number) {
            $result -= $number;
        }

        return $result;
    }

    /**
     * @param  array<int, float>  $numbers
     */
    private function multiply(array $numbers): float
    {
        $result = 1.0;

        foreach ($numbers as $number) {
            $result *= $number;
        }

        return $result;
    }

    /**
     * @param  array<int, float>  $numbers
     */
    private function divide(array $numbers): float
    {
        $result = array_shift($numbers) ?? 0.0;

        foreach ($numbers as $number) {
            if ($number == 0.0) {
                return $result;
            }

            $result /= $number;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyIf(mixed $values, array $data): mixed
    {
        $items = is_array($values) ? $values : [$values];
        $condition = $items[0] ?? null;
        $truthy = $items[1] ?? null;
        $falsy = $items[2] ?? null;

        return $this->evaluate($condition, $data) ? $this->evaluate($truthy, $data) : $this->evaluate($falsy, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyComparisonOperator(string $operator, mixed $values, array $data): bool
    {
        $items = is_array($values) ? array_values($values) : [$values];

        $left = $this->evaluate($items[0] ?? null, $data);
        $right = $this->evaluate($items[1] ?? null, $data);

        return match ($operator) {
            '==' => $left == $right,
            '===' => $left === $right,
            '!=' => $left != $right,
            '!==' => $left !== $right,
            '>' => $this->compareValues($left, $right) > 0,
            '>=' => $this->compareValues($left, $right) >= 0,
            '<' => $this->compareValues($left, $right) < 0,
            '<=' => $this->compareValues($left, $right) <= 0,
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyLogicalOperator(string $operator, mixed $values, array $data): bool
    {
        $items = is_array($values) ? array_values($values) : [$values];
        $evaluated = array_map(fn (mixed $item): mixed => $this->evaluate($item, $data), $items);

        return match ($operator) {
            'and' => $this->evaluateAllTruthy($evaluated),
            'or' => $this->evaluateAnyTruthy($evaluated),
            '!' => ! $this->isTruthy($evaluated[0] ?? null),
            default => false,
        };
    }

    private function toNumber(mixed $value): float
    {
        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return 0.0;
    }

    private function normalizeUnsignedInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (is_float($value)) {
            if (! is_finite($value) || $value < 0 || floor($value) !== $value || $value > PHP_INT_MAX) {
                return null;
            }

            return (int) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        if ($normalized === '' || ! ctype_digit($normalized)) {
            return null;
        }

        $maxInteger = (string) PHP_INT_MAX;

        if (strlen($normalized) > strlen($maxInteger)) {
            return null;
        }

        if (strlen($normalized) === strlen($maxInteger) && strcmp($normalized, $maxInteger) > 0) {
            return null;
        }

        return (int) $normalized;
    }

    private function reinterpretBigEndianFloatFromInteger(int $value, int $byteLength): ?float
    {
        if ($value < 0) {
            return null;
        }

        if ($byteLength === 4 && $value > 0xFFFFFFFF) {
            return null;
        }

        $hex = str_pad(dechex($value), $byteLength * 2, '0', STR_PAD_LEFT);

        return $this->decodeBigEndianFloat($hex, $byteLength);
    }

    /**
     * Decodes an unsigned integer bit pattern as signed two's-complement.
     *
     * @param  array<string, mixed>  $data
     */
    private function applyDecodeTwosComplement(mixed $values, array $data): mixed
    {
        $items = is_array($values) ? array_values($values) : [$values];
        $rawValue = $this->evaluate($items[0] ?? null, $data);
        $bitLength = $this->normalizeBitLength($this->evaluate($items[1] ?? 32, $data));

        if ($bitLength === null) {
            return $rawValue;
        }

        $integerValue = $this->normalizeUnsignedInteger($rawValue);

        if ($integerValue === null) {
            return is_int($rawValue) && $rawValue < 0 ? $rawValue : $rawValue;
        }

        return $this->decodeTwosComplementFromInteger($integerValue, $bitLength) ?? $rawValue;
    }

    private function normalizeBitLength(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $bitLength = (int) $value;

        return $bitLength > 0 && $bitLength <= (PHP_INT_SIZE * 8) - 2 ? $bitLength : null;
    }

    private function decodeTwosComplementFromInteger(int $value, int $bitLength): ?int
    {
        if ($value < 0) {
            return $value;
        }

        $maxValue = (1 << $bitLength) - 1;

        if ($value > $maxValue) {
            return null;
        }

        $signBit = 1 << ($bitLength - 1);

        if (($value & $signBit) === 0) {
            return $value;
        }

        return $value - (1 << $bitLength);
    }

    private function decodeBigEndianFloat(string $hex, int $expectedByteLength): ?float
    {
        if (strlen($hex) !== $expectedByteLength * 2) {
            return null;
        }

        $binary = @hex2bin($hex);

        if (! is_string($binary) || strlen($binary) !== $expectedByteLength) {
            return null;
        }

        $format = $expectedByteLength === 8 ? 'E' : 'G';
        $decoded = unpack($format, $binary);

        if (! is_array($decoded)) {
            return null;
        }

        $value = $decoded[1] ?? null;

        return is_float($value) || is_int($value)
            ? (float) $value
            : null;
    }

    private function compareValues(mixed $left, mixed $right): int
    {
        if (is_numeric($left) && is_numeric($right)) {
            return $left <=> $right;
        }

        $leftString = is_scalar($left) || $left instanceof \Stringable ? (string) $left : '';
        $rightString = is_scalar($right) || $right instanceof \Stringable ? (string) $right : '';

        return $leftString <=> $rightString;
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private function evaluateAllTruthy(array $values): bool
    {
        foreach ($values as $value) {
            if (! $this->isTruthy($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private function evaluateAnyTruthy(array $values): bool
    {
        foreach ($values as $value) {
            if ($this->isTruthy($value)) {
                return true;
            }
        }

        return false;
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (float) $value !== 0.0;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            return ! in_array($normalized, ['', '0', 'false', 'off', 'no', 'null'], true);
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return $value !== null;
    }

    /**
     * @param  array<mixed, mixed>  $value
     */
    private function isAssoc(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }
}
