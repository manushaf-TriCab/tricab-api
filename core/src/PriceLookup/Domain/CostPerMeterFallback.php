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
        if (!is_numeric($defaultCost)) {
            return null;
        }

        if ($status !== MakeStockStatus::MAKE) {
            return $defaultCost;
        }

        $length = $userInputLength !== null ? trim($userInputLength) : '';
        if ($length === '' || $length === '0' || !is_numeric($length) || bccomp($length, '0', 6) <= 0) {
            return $defaultCost;
        }

        $setupLen = $defaultLength !== null ? trim($defaultLength) : '';
        if ($setupLen === '' || $setupLen === '0' || !is_numeric($setupLen) || bccomp($setupLen, '0', 6) <= 0) {
            return null;
        }

        $setupCost = $this->setupCost->calculate($productCode);
        // legacy: generatedCost = baseCost + (setupCost / userInputLength)
        $additional = bcdiv($setupCost, $length, PriceLookupMoney::WORK_SCALE);

        return bcadd($defaultCost, $additional, PriceLookupMoney::WORK_SCALE);
    }

    /**
     * @return numeric-string
     */
    public function setupPremium(string $productCode): string
    {
        return $this->setupCost->calculate($productCode);
    }
}
