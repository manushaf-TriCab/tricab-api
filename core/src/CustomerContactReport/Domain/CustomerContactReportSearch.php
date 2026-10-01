<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Domain;

/**
 * Substring search. % and _ are literal characters, matching the legacy
 * LIKE escape, not SQL wildcards.
 */
final class CustomerContactReportSearch
{
    public static function contains(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        return mb_stripos($haystack, $needle) !== false;
    }

    /**
     * `!` is the escape character. A backslash inside a SQL string literal
     * confuses PDO's placeholder scanner, so this matches the RFQ search.
     */
    public static function likePattern(string $needle): string
    {
        return '%' . strtr($needle, ['!' => '!!', '%' => '!%', '_' => '!_', '[' => '![']) . '%';
    }
}
