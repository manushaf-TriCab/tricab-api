<?php

declare(strict_types=1);

namespace TriCab\Core\Commerce\Domain\Exception;

use DomainException;

final class CartCurrencyMismatch extends DomainException
{
    public static function between(string $cartCurrency, string $lineCurrency): self
    {
        return new self(sprintf(
            'Cart currency "%s" does not match line currency "%s".',
            $cartCurrency,
            $lineCurrency,
        ));
    }
}
