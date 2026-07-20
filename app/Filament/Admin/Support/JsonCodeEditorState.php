<?php

declare(strict_types=1);

namespace App\Filament\Admin\Support;

final class JsonCodeEditorState
{
    public static function encode(mixed $value): string
    {
        if (! is_array($value) || $value === []) {
            return '';
        }

        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '';
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function decode(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }
}
