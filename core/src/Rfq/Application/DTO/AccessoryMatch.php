<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\DTO;

/**
 * One accessory the matching engine proposes for a cable: the fields the
 * source's `rfq_product_to_accessory_row` reads off the engine's product
 * (`getProductCode`, `getNoUnits`, `getNoPacks`, `getUnitsPerPack`).
 *
 * The engine decides *which* accessories fit a cable and how many; the price
 * is the pricing pass's, not the engine's (parity plan E7.2).
 */
final class AccessoryMatch
{
    public function __construct(
        public readonly string $productCode,
        public readonly string $description = '',
        public readonly float $unitsPerPack = 1.0,
        public readonly float $noUnits = 0.0,
        public readonly float $noPacks = 0.0,
        /** The engine can return cables too (a calculator match); the source drops them. */
        public readonly bool $isCable = false,
    ) {
    }
}
