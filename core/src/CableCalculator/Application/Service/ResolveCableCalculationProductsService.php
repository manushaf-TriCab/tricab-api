<?php

declare(strict_types=1);

namespace TriCab\Core\CableCalculator\Application\Service;

use TriCab\Core\CableCalculator\Application\DTO\CableCalculationResult;
use TriCab\Core\CableCalculator\Application\ProductResolution\CalculatedProductReference;
use TriCab\Core\CableCalculator\Contract\CableCalculatorProductResolver;

/**
 * Resolves product codes AFTER engineering calculation. Never mutates calculation.
 */
final class ResolveCableCalculationProductsService
{
    public function __construct(
        private readonly CableCalculatorProductResolver $resolver,
    ) {
    }

    /**
     * @return list<CalculatedProductReference>
     */
    public function resolve(CableCalculationResult $result): array
    {
        $refs = [];
        foreach ($result->bands as $band) {
            if ($band->productCodeActive !== '') {
                $refs[] = $this->resolver->resolve(
                    $band->productCodeActive,
                    'ACTIVE',
                    $band->temperatureBand,
                    $band->activeSizeMm2,
                );
            }
            if ($band->productCodeEarth !== null && $band->productCodeEarth !== '') {
                $refs[] = $this->resolver->resolve(
                    $band->productCodeEarth,
                    'EARTH',
                    $band->temperatureBand,
                    $band->earthSizeMm2,
                );
            }
        }

        return $refs;
    }
}
