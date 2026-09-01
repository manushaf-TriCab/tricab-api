<?php

declare(strict_types=1);

namespace TriCab\Core\CableCalculator\Contract;

use TriCab\Core\CableCalculator\Application\ProductResolution\CalculatedProductReference;

/**
 * Resolves calculator product codes against the new Product Module AFTER calculation.
 * Must never affect engineering sizing.
 */
interface CableCalculatorProductResolver
{
    /**
     * @return CalculatedProductReference Exact identity lookup — never substitutes another size/style.
     */
    public function resolve(
        string $productCode,
        string $role,
        string $temperatureBand,
        ?float $sizeMm2,
    ): CalculatedProductReference;
}
