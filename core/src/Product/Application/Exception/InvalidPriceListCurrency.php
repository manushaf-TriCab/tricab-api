<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Exception;

use RuntimeException;

final class InvalidPriceListCurrency extends RuntimeException
{
    public static function mismatch(string $priceListCurrency, string $requestedCurrency): self
    {
        return new self(sprintf(
            'Currency "%s" does not match the price list currency "%s".',
            $requestedCurrency,
            $priceListCurrency,
        ));
    }
}
