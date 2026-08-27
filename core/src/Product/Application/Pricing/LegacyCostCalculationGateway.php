<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Pricing;

/**
 * Boundary for the interim legacy Cost API (authoritative effective cost for MAKE/STOCKED).
 * Always requires a positive length string for the HTTP call (probe with "1" when unknown).
 */
interface LegacyCostCalculationGateway
{
    public function resolveCost(string $productCode, string $dbco, string $requestedLength): CostResolution;
}
