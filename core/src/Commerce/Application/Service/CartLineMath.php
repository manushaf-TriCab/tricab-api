<?php

declare(strict_types=1);

namespace TriCab\Core\Commerce\Application\Service;

use TriCab\Core\Commerce\Domain\Exception\InvalidCartValue;

/**
 * Shared helpers for cart quantity / length / line totals.
 */
final class CartLineMath
{
    public static function normalizeLength(int|float|string $length): string
    {
        if (is_int($length) || is_float($length)) {
            $normalized = number_format((float) $length, 3, '.', '');
        } else {
            $normalized = trim($length);
            if ($normalized === '' || !is_numeric($normalized)) {
                throw InvalidCartValue::for('Length', (string) $length);
            }
            $normalized = number_format((float) $normalized, 3, '.', '');
        }

        if (bccomp($normalized, '0', 3) <= 0) {
            throw InvalidCartValue::for('Length', $normalized);
        }

        return $normalized;
    }

    public static function lineTotal(?string $unitSellPrice, int $quantity, ?string $length): ?string
    {
        if ($unitSellPrice === null || $length === null) {
            return null;
        }
        if (bccomp($unitSellPrice, '0', 6) <= 0) {
            return null;
        }

        $metres = bcmul((string) $quantity, $length, 6);

        return bcmul($unitSellPrice, $metres, 2);
    }
}
