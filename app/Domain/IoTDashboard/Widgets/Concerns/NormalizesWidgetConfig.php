<?php

declare(strict_types=1);

namespace App\Domain\IoTDashboard\Widgets\Concerns;

trait NormalizesWidgetConfig
{
    /**
     * @param  array<int, mixed>|mixed  $series
     * @return array<int, array{key: string, label: string, color: string, unit?: string|null}>
     */
    private static function normalizeSeries(mixed $series): array
    {
        if (! is_array($series)) {
            return [];
        }

        $normalized = [];
        $seen = [];

        foreach (array_values($series) as $index => $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $key = is_string($entry['key'] ?? null)
                ? trim((string) $entry['key'])
                : '';

            if ($key === '' || in_array($key, $seen, true)) {
                continue;
            }

            $seen[] = $key;
            $normalized[] = [
                'key' => $key,
                'label' => is_string($entry['label'] ?? null) && trim((string) $entry['label']) !== ''
                    ? (string) $entry['label']
                    : $key,
                'color' => is_string($entry['color'] ?? null) && trim((string) $entry['color']) !== ''
                    ? (string) $entry['color']
                    : self::seriesPalette()[$index % count(self::seriesPalette())],
                'unit' => is_string($entry['unit'] ?? null) && trim((string) $entry['unit']) !== ''
                    ? trim((string) $entry['unit'])
                    : null,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int, mixed>|mixed  $rows
     * @param  array<int, array{key: string, label: string, color: string, unit?: string|null}>  $series
     * @return array<int, array{parameter_keys: array<int, string>}>
     */
    private static function normalizeStatusSummaryRows(mixed $rows, array $series): array
    {
        $allowedKeys = array_map(
            static fn (array $entry): string => $entry['key'],
            $series,
        );

        if ($allowedKeys === []) {
            return [];
        }

        if (! is_array($rows) || $rows === []) {
            return self::defaultStatusSummaryRows($series);
        }

        $normalized = [];
        $seen = [];

        foreach (array_values($rows) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $parameterKeys = [];

            foreach (array_values(is_array($row['parameter_keys'] ?? null) ? $row['parameter_keys'] : []) as $parameterKey) {
                if (! is_string($parameterKey)) {
                    continue;
                }

                $parameterKey = trim($parameterKey);

                if (
                    $parameterKey === ''
                    || in_array($parameterKey, $seen, true)
                    || ! in_array($parameterKey, $allowedKeys, true)
                ) {
                    continue;
                }

                $seen[] = $parameterKey;
                $parameterKeys[] = $parameterKey;
            }

            if ($parameterKeys === []) {
                continue;
            }

            $normalized[] = [
                'parameter_keys' => $parameterKeys,
            ];
        }

        $missingKeys = array_values(array_diff($allowedKeys, $seen));

        if ($missingKeys !== []) {
            foreach (array_chunk($missingKeys, 3) as $chunk) {
                $normalized[] = [
                    'parameter_keys' => $chunk,
                ];
            }
        }

        return $normalized === [] ? self::defaultStatusSummaryRows($series) : $normalized;
    }

    /**
     * @param  array<int, array{key: string, label: string, color: string, unit?: string|null}>  $series
     * @return array<int, array{parameter_keys: array<int, string>}>
     */
    private static function defaultStatusSummaryRows(array $series): array
    {
        $energyKeys = [];
        $voltageKeys = [];
        $currentKeys = [];
        $otherKeys = [];

        foreach ($series as $entry) {
            $key = $entry['key'];

            if (str_contains(strtolower($key), 'energy')) {
                $energyKeys[] = $key;

                continue;
            }

            if (preg_match('/^V\d+$/i', $key) === 1) {
                $voltageKeys[] = $key;

                continue;
            }

            if (preg_match('/^A\d+$/i', $key) === 1) {
                $currentKeys[] = $key;

                continue;
            }

            $otherKeys[] = $key;
        }

        $rows = [];

        foreach ([$energyKeys, $voltageKeys, $currentKeys] as $rowKeys) {
            if ($rowKeys === []) {
                continue;
            }

            $rows[] = [
                'parameter_keys' => $rowKeys,
            ];
        }

        foreach (array_chunk($otherKeys, 3) as $chunk) {
            $rows[] = [
                'parameter_keys' => $chunk,
            ];
        }

        if ($rows === []) {
            foreach (array_chunk(array_map(
                static fn (array $entry): string => $entry['key'],
                $series,
            ), 3) as $chunk) {
                $rows[] = [
                    'parameter_keys' => $chunk,
                ];
            }
        }

        return $rows;
    }

    /**
     * @return array<int, string>
     */
    private static function seriesPalette(): array
    {
        return [
            '#22d3ee',
            '#a855f7',
            '#f97316',
            '#10b981',
            '#f43f5e',
            '#3b82f6',
            '#f59e0b',
            '#14b8a6',
        ];
    }

    /**
     * @param  array<int, mixed>|mixed  $mappings
     * @return array<int, array{value: string, label: string, color: string}>
     */
    private static function normalizeStateMappings(mixed $mappings): array
    {
        if (! is_array($mappings)) {
            return [];
        }

        $normalized = [];
        $seen = [];

        foreach (array_values($mappings) as $index => $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $value = self::normalizeStateValueKey($entry['value'] ?? null);

            if ($value === '' || in_array($value, $seen, true)) {
                continue;
            }

            $seen[] = $value;
            $normalized[] = [
                'value' => $value,
                'label' => is_string($entry['label'] ?? null) && trim((string) $entry['label']) !== ''
                    ? trim((string) $entry['label'])
                    : $value,
                'color' => is_string($entry['color'] ?? null) && trim((string) $entry['color']) !== ''
                    ? trim((string) $entry['color'])
                    : self::statePalette()[$index % count(self::statePalette())],
            ];
        }

        return $normalized;
    }

    private static function normalizeStateValueKey(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            $normalized = rtrim(rtrim(sprintf('%.12F', $value), '0'), '.');

            return $normalized === '' ? '0' : $normalized;
        }

        if (is_string($value)) {
            return trim($value);
        }

        if ($value instanceof \Stringable) {
            return trim((string) $value);
        }

        return '';
    }

    /**
     * @return array<int, string>
     */
    private static function statePalette(): array
    {
        return [
            '#22c55e',
            '#ef4444',
            '#f59e0b',
            '#3b82f6',
            '#8b5cf6',
            '#06b6d4',
            '#f97316',
            '#64748b',
        ];
    }

    private static function normalizeBool(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (bool) ((int) $value);
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        return $default;
    }

    private static function normalizeInt(mixed $value, int $default, int $minimum, int $maximum): int
    {
        $resolved = is_numeric($value)
            ? (int) round((float) $value)
            : $default;

        return min(max($resolved, $minimum), $maximum);
    }

    private static function normalizeFloat(mixed $value, float $default): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        return $default;
    }
}
