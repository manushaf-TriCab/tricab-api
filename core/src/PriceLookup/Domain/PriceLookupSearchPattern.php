<?php

declare(strict_types=1);

namespace TriCab\Core\PriceLookup\Domain;

/**
 * Convert user search q into a parameterized LIKE pattern (* → %, ? → _).
 */
final class PriceLookupSearchPattern
{
    public function toLikePattern(string $q): string
    {
        $trimmed = trim($q);
        $pattern = str_replace(['*', '?'], ['%', '_'], $trimmed);
        if ($pattern === '') {
            return '%';
        }
        if (!str_ends_with($pattern, '%')) {
            $pattern .= '%';
        }

        return $pattern;
    }
}
