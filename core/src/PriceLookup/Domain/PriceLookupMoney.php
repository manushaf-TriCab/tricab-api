<?php

declare(strict_types=1);

namespace TriCab\Core\PriceLookup\Domain;

/**
 * BCMath helpers for Price Lookup display (legacy printf %2.2f).
 */
final class PriceLookupMoney
{
    public const DISPLAY_SCALE = 2;
    public const WORK_SCALE = 12;

    /**
     * adjusted = cost / crate / mrate
     *
     * @return numeric-string
     */
    public function adjustedCost(string $cost, string $crate, string $mrate): string
    {
        if (!is_numeric($cost) || !is_numeric($crate) || !is_numeric($mrate) || bccomp($crate, '0', self::WORK_SCALE) <= 0 || bccomp($mrate, '0', self::WORK_SCALE) <= 0) {
            throw new \InvalidArgumentException('Invalid cost, crate or mrate.');
        }

        return bcdiv(bcdiv($cost, $crate, self::WORK_SCALE), $mrate, self::WORK_SCALE);
    }

    /**
     * Round half-up to 2 decimal places (legacy %2.2f style).
     *
     * @return numeric-string
     */
    public function roundDisplay(string $amount): string
    {
        if (!is_numeric($amount)) {
            return '0.00';
        }

        $negative = bccomp($amount, '0', self::WORK_SCALE) < 0;
        $abs = $negative ? bcmul($amount, '-1', self::WORK_SCALE) : $amount;
        $scaled = bcmul($abs, '100', self::WORK_SCALE);
        $rounded = bcadd($scaled, '0.5', 0);
        $result = bcdiv($rounded, '100', self::DISPLAY_SCALE);

        return $negative ? bcmul($result, '-1', self::DISPLAY_SCALE) : $result;
    }
}
