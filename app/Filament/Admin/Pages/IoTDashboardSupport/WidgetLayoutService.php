<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\IoTDashboardSupport;

class WidgetLayoutService
{
    public const int GRID_COLUMNS = 24;

    public const int GRID_CELL_HEIGHT = 96;

    /**
     * @param  array<string, mixed>  $data
     * @param  array{x: int, y: int, w: int, h: int, columns?: int, card_height_px?: int}|null  $defaultLayout
     * @return array{x: int, y: int, w: int, h: int, columns: int, card_height_px: int}
     */
    public function buildLayout(array $data, ?array $defaultLayout = null): array
    {
        $fallback = $defaultLayout ?? [
            'x' => 0,
            'y' => 0,
            'w' => 6,
            'h' => 4,
        ];

        $width = $this->normalizeInt($data['grid_columns'] ?? $fallback['w'], $fallback['w'], 1, self::GRID_COLUMNS);
        $height = $this->gridRowsFromCardHeight(
            $data['card_height_px'] ?? ($fallback['h'] * self::GRID_CELL_HEIGHT),
        );

        return [
            'x' => max($this->toInt($fallback['x'], 0), 0),
            'y' => max($this->toInt($fallback['y'], 0), 0),
            'w' => $width,
            'h' => $height,
            'columns' => self::GRID_COLUMNS,
            'card_height_px' => $height * self::GRID_CELL_HEIGHT,
        ];
    }

    /**
     * @param  array{x: int, y: int, w: int, h: int, columns?: int, card_height_px?: int}  $sourceLayout
     * @param  array<int, array{x: int, y: int, w: int, h: int, columns?: int, card_height_px?: int}>  $occupiedLayouts
     * @return array{x: int, y: int, w: int, h: int, columns: int, card_height_px: int}
     */
    public function duplicateLayout(array $sourceLayout, array $occupiedLayouts): array
    {
        $source = $this->normalizeLayout($sourceLayout);
        $occupied = array_map(fn (array $layout): array => $this->normalizeLayout($layout), $occupiedLayouts);

        $rightCandidate = [
            ...$source,
            'x' => $source['x'] + $source['w'],
        ];

        if (
            ($rightCandidate['x'] + $rightCandidate['w']) <= self::GRID_COLUMNS
            && ! $this->hasCollision($rightCandidate, $occupied)
        ) {
            return $rightCandidate;
        }

        $belowCandidate = [
            ...$source,
            'y' => $source['y'] + $source['h'],
        ];

        while ($this->hasCollision($belowCandidate, $occupied)) {
            $belowCandidate['y'] = $this->nextAvailableRow($belowCandidate, $occupied);
        }

        return $belowCandidate;
    }

    /**
     * @return array<int|string, string>
     */
    public function gridColumnOptions(): array
    {
        $options = [];

        foreach (range(1, self::GRID_COLUMNS) as $column) {
            $suffix = $column === 1 ? 'column' : 'columns';
            $options[(string) $column] = "{$column} {$suffix}";
        }

        return $options;
    }

    public function gridRowsFromCardHeight(mixed $cardHeight): int
    {
        $heightPx = $this->normalizeInt($cardHeight, 360, 260, 900);

        return $this->normalizeInt((int) round($heightPx / self::GRID_CELL_HEIGHT), 4, 2, 12);
    }

    private function toInt(mixed $value, int $default = 0): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    private function normalizeInt(mixed $value, int $default, int $min, int $max): int
    {
        return min(max($this->toInt($value, $default), $min), $max);
    }

    /**
     * @param  array{x: int, y: int, w: int, h: int, columns?: int, card_height_px?: int}  $layout
     * @return array{x: int, y: int, w: int, h: int, columns: int, card_height_px: int}
     */
    private function normalizeLayout(array $layout): array
    {
        $width = $this->normalizeInt($layout['w'], 6, 1, self::GRID_COLUMNS);
        $height = $this->normalizeInt($layout['h'], 4, 2, 12);

        return [
            'x' => max($this->toInt($layout['x'], 0), 0),
            'y' => max($this->toInt($layout['y'], 0), 0),
            'w' => $width,
            'h' => $height,
            'columns' => self::GRID_COLUMNS,
            'card_height_px' => $height * self::GRID_CELL_HEIGHT,
        ];
    }

    /**
     * @param  array{x: int, y: int, w: int, h: int, columns: int, card_height_px: int}  $candidate
     * @param  array<int, array{x: int, y: int, w: int, h: int, columns: int, card_height_px: int}>  $occupiedLayouts
     */
    private function hasCollision(array $candidate, array $occupiedLayouts): bool
    {
        foreach ($occupiedLayouts as $layout) {
            if (
                $candidate['x'] < ($layout['x'] + $layout['w'])
                && ($candidate['x'] + $candidate['w']) > $layout['x']
                && $candidate['y'] < ($layout['y'] + $layout['h'])
                && ($candidate['y'] + $candidate['h']) > $layout['y']
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{x: int, y: int, w: int, h: int, columns: int, card_height_px: int}  $candidate
     * @param  array<int, array{x: int, y: int, w: int, h: int, columns: int, card_height_px: int}>  $occupiedLayouts
     */
    private function nextAvailableRow(array $candidate, array $occupiedLayouts): int
    {
        $nextRow = $candidate['y'] + 1;

        foreach ($occupiedLayouts as $layout) {
            if (
                $candidate['x'] < ($layout['x'] + $layout['w'])
                && ($candidate['x'] + $candidate['w']) > $layout['x']
                && $candidate['y'] < ($layout['y'] + $layout['h'])
                && ($candidate['y'] + $candidate['h']) > $layout['y']
            ) {
                $nextRow = max($nextRow, $layout['y'] + $layout['h']);
            }
        }

        return $nextRow;
    }
}
