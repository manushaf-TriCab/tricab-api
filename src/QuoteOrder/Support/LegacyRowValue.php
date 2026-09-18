<?php

declare(strict_types=1);

namespace TriCab\Api\QuoteOrder\Support;

/**
 * Narrows untrusted legacy PDO / Azure SQL cell values for QuoteOrder mappers.
 * Does not invent data — unknown shapes fall back to empty/null/default.
 */
final class LegacyRowValue
{
    public static function string(mixed $value, string $default = ''): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $default;
    }

    public static function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim(self::string($value));

        return $trimmed === '' ? null : $trimmed;
    }

    public static function int(mixed $value, int $default = 0): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    /**
     * @return string|int|null
     */
    public static function statusCode(mixed $value): string|int|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_string($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (string) $value;
        }

        return null;
    }

    /**
     * @return string|int|float|null
     */
    public static function moneyNet(mixed $value): string|int|float|null
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function assocRows(mixed $fetched): array
    {
        if (!is_array($fetched)) {
            return [];
        }

        $out = [];
        foreach ($fetched as $row) {
            if (!is_array($row)) {
                continue;
            }
            $assoc = [];
            foreach ($row as $key => $cell) {
                if (is_string($key)) {
                    $assoc[$key] = $cell;
                }
            }
            $out[] = $assoc;
        }

        return $out;
    }
}
