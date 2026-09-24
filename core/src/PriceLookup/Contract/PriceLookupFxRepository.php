<?php

declare(strict_types=1);

namespace TriCab\Core\PriceLookup\Contract;

/**
 * Latest FX rate from legacy descript/cyrte for Price Lookup currencies.
 */
interface PriceLookupFxRepository
{
    /**
     * @return string|null Rate as decimal string, or null when missing
     */
    public function findLatestRate(string $currency): ?string;
}
