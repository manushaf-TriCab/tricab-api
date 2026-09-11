<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Domain\Service;

final class ExpenseVendorNormalizer
{
    public static function normalize(string $vendor): string
    {
        $collapsed = preg_replace('/[^a-z0-9 ]+/i', ' ', $vendor) ?? $vendor;
        $collapsed = preg_replace('/\s+/', ' ', $collapsed) ?? $collapsed;

        return strtoupper(trim($collapsed));
    }

    public static function isFuzzyMatch(string $left, string $right): bool
    {
        $a = self::normalize($left);
        $b = self::normalize($right);
        if ($a === '' || $b === '') {
            return false;
        }

        return str_contains($a, $b) || str_contains($b, $a);
    }
}
