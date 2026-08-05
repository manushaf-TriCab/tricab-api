<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence;

/**
 * Decimal persistence without floating-point conversion.
 * SQL SELECT should CAST/CONVERT decimals to NVARCHAR; this mapper only normalizes strings.
 */
final class ProductDecimalMapper
{
    public static function toNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            // Avoid float paths for money; only used if driver mis-typed a column.
            return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.') ?: '0';
        }

        if (!is_string($value)) {
            return null;
        }

        $normalized = trim($value);
        if ($normalized === '' || !is_numeric($normalized)) {
            return null;
        }

        return $normalized;
    }

    public static function requireString(mixed $value, string $field): string
    {
        $normalized = self::toNullableString($value);
        if ($normalized === null) {
            throw new \RuntimeException(sprintf('Missing decimal value for %s.', $field));
        }

        return $normalized;
    }
}
