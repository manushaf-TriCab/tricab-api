<?php

declare(strict_types=1);

namespace TriCab\Core\Commerce\Domain\Exception;

use DomainException;

final class CartConcurrencyConflict extends DomainException
{
    public static function forCart(): self
    {
        return new self('Cart was modified by another request. Refresh and retry.');
    }

    public static function forLine(): self
    {
        return new self('Cart line was modified by another request. Refresh and retry.');
    }
}
