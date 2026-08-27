<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Pricing;

use TriCab\Core\Product\Application\Pricing\CostResolution;
use TriCab\Core\Product\Application\Pricing\CustomerSellPriceStatus;
use TriCab\Core\Product\Application\Pricing\LegacyCostCalculationGateway;

/** Always disabled — used when LEGACY_COST_API_ENABLED is false. */
final class DisabledLegacyCostCalculationGateway implements LegacyCostCalculationGateway
{
    public function resolveCost(string $productCode, string $dbco, string $requestedLength): CostResolution
    {
        return CostResolution::failure(
            $productCode,
            $dbco,
            'DISABLED',
            CustomerSellPriceStatus::AVAILABILITY_UNKNOWN,
            $requestedLength,
        );
    }
}
