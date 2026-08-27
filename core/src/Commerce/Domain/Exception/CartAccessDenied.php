<?php

declare(strict_types=1);

namespace TriCab\Core\Commerce\Domain\Exception;

use DomainException;

final class CartAccessDenied extends DomainException
{
    public static function notOwner(): self
    {
        return new self('Cart access denied for this actor.');
    }
}
