<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Pricing;

/**
 * Proven legacy catalogue sell-price formula (ProductConfigurator::computeCabPrice / computeAccPrice).
 *
 * Markup-from-margin-percentage — NOT cost * (1 + margin/100).
 * Contract only; does not persist sell prices.
 */
final class LegacyCustomerSellFormulaContract
{
    /**
     * @return numeric-string
     */
    public function convertBaseCost(string $baseCost, string $fxRate): string
    {
        if (!is_numeric($baseCost) || !is_numeric($fxRate) || bccomp($fxRate, '0', 12) === 0) {
            throw new \InvalidArgumentException('Invalid base cost or FX rate.');
        }

        return bcdiv($baseCost, $fxRate, 12);
    }

    /**
     * @return numeric-string
     */
    public function sellFromConvertedCost(string $convertedBaseCost, string $marginPercent): string
    {
        if (!is_numeric($convertedBaseCost) || !is_numeric($marginPercent)) {
            throw new \InvalidArgumentException('Invalid converted cost or margin.');
        }

        // sell = converted * 100 / (100 - margin)
        $denominator = bcsub('100', $marginPercent, 12);
        if (bccomp($denominator, '0', 12) <= 0) {
            throw new \InvalidArgumentException('Margin percent must be less than 100.');
        }

        return bcdiv(bcmul($convertedBaseCost, '100', 12), $denominator, 12);
    }

    /**
     * Full catalogue path without TAS special rule.
     *
     * @return numeric-string
     */
    public function resolveSellPrice(string $baseCost, string $fxRate, string $marginPercent): string
    {
        $converted = $this->convertBaseCost($baseCost, $fxRate);

        return $this->sellFromConvertedCost($converted, $marginPercent);
    }

    /**
     * Legacy TAS adjustment: cost *= 100/99 before markup.
     * Classified LEGACY_SPECIAL_RULE_REVIEW — not applied by default.
     *
     * @return numeric-string
     */
    public function applyTasAdjustment(string $baseCost): string
    {
        return bcdiv(bcmul($baseCost, '100', 12), '99', 12);
    }

    public function incorrectMarkupShortcut(string $baseCost, string $marginPercent): string
    {
        // Documented anti-pattern: base * (1 + margin/100)
        $factor = bcadd('1', bcdiv($marginPercent, '100', 12), 12);

        return bcmul($baseCost, $factor, 12);
    }
}
