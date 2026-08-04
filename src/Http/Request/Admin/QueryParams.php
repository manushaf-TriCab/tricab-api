<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Request\Admin;

/**
 * Small typed accessors for Slim's array<string, mixed> query params.
 */
final class QueryParams
{
    /**
     * Normalizes PSR-7's untyped getQueryParams() array (declared as bare
     * `array` with no key/value generics) into array<string, mixed>,
     * dropping any non-string keys defensively.
     *
     * @param array<array-key, mixed> $raw
     * @return array<string, mixed>
     */
    public static function normalize(array $raw): array
    {
        $normalized = [];
        foreach ($raw as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function stringOrNull(array $query, string $key): ?string
    {
        $value = $query[$key] ?? null;
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function string(array $query, string $key, string $default): string
    {
        return self::stringOrNull($query, $key) ?? $default;
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function int(array $query, string $key, int $default): int
    {
        $value = $query[$key] ?? null;
        if (!is_string($value) && !is_int($value)) {
            return $default;
        }

        if (!is_numeric($value)) {
            return $default;
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function boolOrNull(array $query, string $key): ?bool
    {
        $value = $query[$key] ?? null;
        if (!is_string($value) && !is_bool($value)) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return null;
        }

        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return null;
    }
}
