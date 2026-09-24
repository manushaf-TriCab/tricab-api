<?php

declare(strict_types=1);

namespace TriCab\Core\PriceLookup\Domain;

use TriCab\Core\PriceLookup\Application\Exception\PriceLookupValidationException;

/**
 * Staff-entered margin sell — NOT customer margin.
 * sell = adjusted / (1 - m/100) when 0 <= m < 100; else sell = adjusted.
 */
final class StaffEnteredMarginSellFormula
{
    public function apply(string $adjustedCost, ?string $marginPercent): string
    {
        if ($marginPercent === null || trim($marginPercent) === '') {
            return $adjustedCost;
        }

        if (!is_numeric($marginPercent)) {
            throw PriceLookupValidationException::invalidMargin($marginPercent);
        }

        $m = (string) $marginPercent;
        if (bccomp($m, '0', 12) < 0 || bccomp($m, '100', 12) >= 0) {
            throw PriceLookupValidationException::invalidMargin($marginPercent);
        }

        if (bccomp($m, '0', 12) === 0) {
            return $adjustedCost;
        }

        $factor = bcsub('1', bcdiv($m, '100', 12), 12);
        if (bccomp($factor, '0', 12) <= 0) {
            throw PriceLookupValidationException::invalidMargin($marginPercent);
        }

        return bcdiv($adjustedCost, $factor, 12);
    }
}
