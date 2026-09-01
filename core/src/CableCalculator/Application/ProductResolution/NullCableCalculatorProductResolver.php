<?php

declare(strict_types=1);

namespace TriCab\Core\CableCalculator\Application\ProductResolution;

use TriCab\Core\CableCalculator\Contract\CableCalculatorProductResolver;

/**
 * Always returns CATALOGUE_UNAVAILABLE — used for independence tests and when catalogue lookup is disabled.
 */
final class NullCableCalculatorProductResolver implements CableCalculatorProductResolver
{
    public function resolve(
        string $productCode,
        string $role,
        string $temperatureBand,
        ?float $sizeMm2,
    ): CalculatedProductReference {
        return new CalculatedProductReference(
            productCode: $productCode,
            role: $role,
            temperatureBand: $temperatureBand,
            sizeMm2: $sizeMm2,
            resolutionStatus: 'CATALOGUE_UNAVAILABLE',
        );
    }
}
