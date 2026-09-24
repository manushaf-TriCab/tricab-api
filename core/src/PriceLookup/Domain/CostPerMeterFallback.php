<?php

declare(strict_types=1);

namespace TriCab\Core\PriceLookup\Domain;

/**
 * Simplified calculateCostPerMeter for MAKE + length.
 * Complex PA large-cable branches prefer Cost API; when insufficient, caller uses raw price.cost.
 */
final class CostPerMeterFallback
{
    public function __construct(
        private readonly SetupCostCalculator $setupCost = new SetupCostCalculator(),
    ) {
    }

    /**
     * @return numeric-string|null Cost per meter string, or null when fallback is insufficient
     */
    public function calculate(
        string $productCode,
        string $status,
        string $defaultCost,
        ?string $defaultLength,
        ?string $userInputLength,
    ): ?string {
        if ($status !== MakeStockStatus::MAKE) {
            return is_numeric($defaultCost) ? $defaultCost : null;
        }

        $length = $userInputLength !== null ? trim($userInputLength) : '';
        if ($length === '' || $length === '0' || !is_numeric($length) || bccomp($length, '0', 6) <= 0) {
            return is_numeric($defaultCost) ? $defaultCost : null;
        }

        $setupLen = $defaultLength !== null ? trim($defaultLength) : '';
        if ($setupLen === '' || $setupLen === '0' || !is_numeric($setupLen) || bccomp($setupLen, '0', 6) <= 0) {
            return null;
        }

        if (!is_numeric($defaultCost)) {
            return null;
        }

        $setupCost = $this->setupCost->calculate($productCode);
        if (!is_numeric($defaultCost) || !is_numeric($length)) {
            return null;
        }

        // legacy: generatedCost = baseCost + (setupCost / userInputLength)
        $additional = bcdiv($setupCost, $length, PriceLookupMoney::WORK_SCALE);

        return bcadd($defaultCost, $additional, PriceLookupMoney::WORK_SCALE);
    }

    /**
     * @return numeric-string
     */
    public function setupPremium(string $productCode): string
    {
        $premium = $this->setupCost->calculate($productCode);

        return is_numeric($premium) ? $premium : '0';
    }
}
